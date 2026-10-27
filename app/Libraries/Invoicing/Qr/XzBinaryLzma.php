<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing\Qr;

/**
 * Raw LZMA1 cez binarku xz. proc_open s polom argumentov (bez shellu), vstup len cez stdin (T-06-17).
 */
final class XzBinaryLzma implements Lzma
{
    private const COMMAND = ['xz', '--format=raw', '--lzma1=preset=9,lc=3,lp=0,pb=2,dict=128KiB', '-c'];

    public function compress(string $raw): string
    {
        $pipes = [];
        $proc  = @proc_open(self::COMMAND, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($proc)) {
            throw new QrPaymentFailed('xz is not runnable');
        }

        fwrite($pipes[0], $raw);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        if (proc_close($proc) !== 0 || $out === '') {
            throw new QrPaymentFailed('xz failed: ' . $err);
        }

        return $out;
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
