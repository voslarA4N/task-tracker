<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing\Qr;

use Throwable;

/**
 * Raw LZMA1 cez binarku xz. proc_open s polom argumentov (bez shellu), vstup len cez stdin (T-06-17).
 *
 * Volanie bezi vnutri transakcie vystavenia (zamky FOR UPDATE), preto ma casovy limit necinnosti (5 s), neblokujuce
 * rury so stream_select (bez deadlocku stdout/stderr) a kazde zlyhanie I/O sa mapuje na QrPaymentFailed (WR-04).
 */
final class XzBinaryLzma implements Lzma
{
    public const TIMEOUT_MS = 5000;
    private const COMMAND   = ['xz', '--format=raw', '--lzma1=preset=9,lc=3,lp=0,pb=2,dict=128KiB', '-c'];
    private const CHUNK     = 65536;

    /**
     * Jedno cakanie stream_select. Limit sa pocita z cakani bez aktivity (bez hodin, ktore su mimo Clock zakazane):
     * zaseknuty xz nic nezapisuje ani necita, takze kazde kolo bez udalosti ubera cely krok.
     */
    private const SLICE_MS = 50;

    /**
     * @param list<string> $command   prikaz (testy podstrcia iny proces)
     * @param int          $timeoutMs najdlhsi sucet cakani bez aktivity
     */
    public function __construct(
        private readonly array $command = self::COMMAND,
        private readonly int $timeoutMs = self::TIMEOUT_MS,
    ) {
    }

    public function compress(string $raw): string
    {
        $pipes = [];
        $proc  = @proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($proc)) {
            throw new QrPaymentFailed('xz is not runnable', QrPaymentFailed::COMPRESSION_FAILED);
        }

        try {
            [$out, $err] = $this->exchange($pipes, $raw);
        } catch (Throwable $e) {
            self::kill($proc, $pipes);

            throw $e instanceof QrPaymentFailed ? $e : new QrPaymentFailed('xz I/O failed: ' . $e->getMessage(), QrPaymentFailed::COMPRESSION_FAILED);
        }

        if (proc_close($proc) !== 0 || $out === '') {
            throw new QrPaymentFailed('xz failed: ' . $err, QrPaymentFailed::COMPRESSION_FAILED);
        }

        return $out;
    }

    /**
     * Zapise vstup a precita stdout aj stderr naraz (stream_select) do uplynutia limitu.
     *
     * @param array<int, resource> $pipes
     *
     * @return array{0: string, 1: string}
     */
    private function exchange(array $pipes, string $raw): array
    {
        $idleMs   = 0;
        $length   = strlen($raw);
        $offset   = 0;
        $buffer   = ['out' => '', 'err' => ''];
        $readable = ['out' => $pipes[1], 'err' => $pipes[2]];
        $stdin    = $pipes[0];

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        if ($length === 0) {
            fclose($stdin);
            $stdin = null;
        }

        while ($readable !== []) {
            if ($idleMs >= $this->timeoutMs) {
                throw new QrPaymentFailed('xz timed out after ' . $this->timeoutMs . ' ms without activity', QrPaymentFailed::COMPRESSION_FAILED);
            }

            $r     = array_values($readable);
            $w     = $stdin === null ? [] : [$stdin];
            $e     = null;
            $ready = @stream_select($r, $w, $e, 0, self::SLICE_MS * 1000);

            if ($ready === false) {
                throw new QrPaymentFailed('xz stream_select failed', QrPaymentFailed::COMPRESSION_FAILED);
            }

            if ($ready === 0) {
                $idleMs += self::SLICE_MS;

                continue;
            }

            if ($stdin !== null && $w !== []) {
                $written = @fwrite($stdin, substr($raw, $offset, self::CHUNK));

                if ($written === false) {
                    throw new QrPaymentFailed('xz stdin write failed', QrPaymentFailed::COMPRESSION_FAILED);
                }

                $offset += $written;

                if ($offset >= $length) {
                    fclose($stdin);
                    $stdin = null;
                }
            }

            foreach ($r as $stream) {
                $key   = array_search($stream, $readable, true) === 'out' ? 'out' : 'err';
                $chunk = @fread($stream, self::CHUNK);

                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    fclose($stream);
                    unset($readable[$key]);

                    continue;
                }

                $buffer[$key] .= $chunk;
            }
        }

        if ($stdin !== null) {
            throw new QrPaymentFailed('xz closed its output before reading all input', QrPaymentFailed::COMPRESSION_FAILED);
        }

        return [$buffer['out'], $buffer['err']];
    }

    /**
     * @param resource             $proc
     * @param array<int, resource> $pipes
     */
    private static function kill($proc, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        @proc_terminate($proc, 9);
        @proc_close($proc);
    }

    public static function available(): bool
    {
        $pipes = [];
        $proc  = @proc_open(['xz', '--version'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($proc)) {
            return false;
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc) === 0;
    }
}
