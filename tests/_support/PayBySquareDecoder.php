<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Dekoder PAY by square pre round-trip testy.
 */
final class PayBySquareDecoder
{
    /**
     * @return array{header: string, length: int, raw_len: int, crc_ok: bool, fields: list<string>}
     */
    public static function decode(string $payload): array
    {
        $bin = self::fromBase32hex($payload);

        $header = substr($bin, 0, 2);
        /** @var array{1: int} $len */
        $len  = unpack('v', substr($bin, 2, 2));
        $lzma = substr($bin, 4);

        $raw = self::decompress($lzma);
        /** @var array{1: int} $crc */
        $crc = unpack('V', substr($raw, 0, 4));
        $tab = substr($raw, 4);

        return [
            'header'  => $header,
            'length'  => $len[1],
            'raw_len' => strlen($raw),
            'crc_ok'  => $crc[1] === crc32($tab),
            'fields'  => explode("\t", $tab),
        ];
    }

    private static function fromBase32hex(string $s): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUV';
        $bits     = 0;
        $acc      = 0;
        $out      = '';

        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $acc = ($acc << 5) | (int) strpos($alphabet, $s[$i]);
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($acc >> $bits) & 255);
                $acc &= (1 << $bits) - 1;
            }
        }

        return $out;
    }

    private static function decompress(string $lzma): string
    {
        $pipes = [];
        $proc  = proc_open(['xz', '-dc', '--format=raw', '--lzma1=lc=3,lp=0,pb=2,dict=128KiB'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($proc)) {
            throw new RuntimeException('xz not runnable');
        }

        fwrite($pipes[0], $lzma);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $out;
    }
}
