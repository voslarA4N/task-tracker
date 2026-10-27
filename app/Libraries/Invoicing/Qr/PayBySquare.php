<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing\Qr;

use InvalidArgumentException;
use Transliterator;

/**
 * Serializer PAY by square (verzia 1.1.0): base32hex(header || length || lzma(crc32 || tab polia)).
 */
final class PayBySquare
{
    /**
     * Verzia formatu (nibble): 0x01 = 1.1.0. Jedna konstanta (Assumption A1, potvrdi UAT).
     */
    public const VERSION = 0x01;

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUV';

    private readonly Lzma $lzma;

    public function __construct(?Lzma $lzma = null)
    {
        $this->lzma = $lzma ?? new XzBinaryLzma();
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @throws QrPaymentFailed
     */
    public function encode(array $snapshot): string
    {
        $tab = implode("\t", $this->tabFields($snapshot));
        $raw = pack('V', crc32($tab)) . $tab;
        $bin = chr(self::VERSION) . "\x00" . pack('v', strlen($raw)) . $this->lzma->compress($raw);

        return self::base32hex($bin);
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return list<string>
     */
    public function tabFields(array $snapshot): array
    {
        $number = $snapshot['number'] ?? null;

        if (! is_string($number) || $number === '') {
            throw new InvalidArgumentException('Snapshot has no invoice number.');
        }

        $entity = (array) $snapshot['entity'];
        $bank   = (array) $snapshot['bank'];
        $labels = (array) $snapshot['labels'];
        $vs     = $snapshot['variable_symbol'] ?? null;
        $title  = self::ascii((string) ($labels['invoice'] ?? '') . ' ' . $number);
        $title  = substr($title, 0, 140);
        $due    = str_replace('-', '', (string) $snapshot['due_date']);
        $iban   = str_replace(' ', '', (string) $bank['iban']);
        $totals = (array) $snapshot['totals'];

        return [
            '',
            '1',
            '1',
            self::amount((string) $totals['total']),
            (string) $snapshot['currency'],
            $due,
            is_string($vs) ? $vs : '',
            '',
            '',
            '',
            trim($title),
            '1',
            $iban,
            (string) ($bank['swift'] ?? ''),
            '0',
            '0',
            substr(self::ascii((string) $entity['trade_name']), 0, 70),
            self::ascii((string) $entity['address_line1']),
            self::ascii((string) $entity['postal_code'] . ' ' . (string) $entity['city']),
        ];
    }

    public static function base32hex(string $bin): string
    {
        $out  = '';
        $bits = 0;
        $acc  = 0;
        $len  = strlen($bin);

        for ($i = 0; $i < $len; $i++) {
            $acc = ($acc << 8) | ord($bin[$i]);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($acc >> $bits) & 31];
            }

            $acc &= (1 << $bits) - 1;
        }

        if ($bits > 0) {
            $out .= self::ALPHABET[($acc << (5 - $bits)) & 31];
        }

        return $out;
    }

    /**
     * Desatinne cislo "1080.50" -> "1080.5" (retazcovo, bez float).
     */
    public static function amount(string $decimal): string
    {
        if (preg_match('/^\d+(\.\d+)?$/', $decimal) !== 1) {
            throw new InvalidArgumentException('Invalid decimal amount: ' . $decimal);
        }

        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    public static function ascii(string $s): string
    {
        $t = Transliterator::create('Any-Latin; Latin-ASCII');
        $r = $t === null ? false : $t->transliterate($s);
        $s = $r === false ? $s : $r;

        return (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $s);
    }
}
