<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing\Qr;

/**
 * QR platba podla Rekomendacji ZBP (PL): 9 poli oddelenych '|'.
 */
final class PolishZbpQr
{
    public const TITLE_MAX = 32;

    /**
     * Pole 4 (suma v groszoch) ma podla Rekomendacji ZBP presne 6 cislic, teda najviac 9 999,99 PLN.
     */
    public const AMOUNT_MAX_GROSZE_DIGITS = 6;

    public static function titleFits(string $number): bool
    {
        return mb_strlen($number) <= self::TITLE_MAX;
    }

    /**
     * Suma (format "1234.56") sa zmesti do 6-cifernych groszy. Vyssia suma: bez QR namiesto chybneho pola (CR-01).
     */
    public static function amountFits(string $total): bool
    {
        return preg_match('/^(\d+)\.(\d{2})$/', $total, $a) === 1
            && strlen(ltrim($a[1] . $a[2], '0')) <= self::AMOUNT_MAX_GROSZE_DIGITS;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function applies(array $snapshot): bool
    {
        $client = (array) ($snapshot['client'] ?? []);
        $bank   = (array) ($snapshot['bank'] ?? []);
        $iban   = str_replace(' ', '', (string) ($bank['iban'] ?? ''));

        return ($client['country'] ?? null) === 'PL'
            && ($snapshot['currency'] ?? null) === 'PLN'
            && preg_match('/^PL\d{26}$/', $iban) === 1;
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @throws QrPaymentFailed
     */
    public function encode(array $snapshot): string
    {
        $entity = (array) $snapshot['entity'];
        $bank   = (array) $snapshot['bank'];
        $number = (string) $snapshot['number'];
        $nip    = (string) preg_replace('/^PL/i', '', (string) preg_replace('/[\s-]/', '', (string) ($entity['tax_id'] ?? '')));

        if (preg_match('/^\d{10}$/', $nip) !== 1) {
            throw new QrPaymentFailed('Entity NIP missing or invalid for ZBP QR.');
        }

        if (! self::titleFits($number)) {
            throw new QrPaymentFailed('Invoice number longer than ' . self::TITLE_MAX . ' characters.');
        }

        $iban = str_replace(' ', '', (string) $bank['iban']);

        if (preg_match('/^PL(\d{26})$/', $iban, $m) !== 1) {
            throw new QrPaymentFailed('IBAN is not a Polish NRB.');
        }

        $total = (string) ((array) $snapshot['totals'])['total'];

        if (preg_match('/^(\d+)\.(\d{2})$/', $total, $a) !== 1) {
            throw new QrPaymentFailed('ZBP amount invalid.');
        }

        $grosze = ltrim($a[1] . $a[2], '0');

        if (strlen($grosze) > self::AMOUNT_MAX_GROSZE_DIGITS) {
            throw new QrPaymentFailed('ZBP amount exceeds 9999.99 PLN.');
        }

        $grosze = str_pad($grosze === '' ? '0' : $grosze, self::AMOUNT_MAX_GROSZE_DIGITS, '0', STR_PAD_LEFT);
        $name   = (string) preg_replace('/[|\x00-\x1F\x7F]/u', '', (string) $entity['trade_name']);
        $name   = trim(mb_substr($name, 0, 20));
        $title  = (string) preg_replace('/[|\x00-\x1F\x7F]/u', '', $number);

        $out = implode('|', [$nip, 'PL', $m[1], $grosze, $name, $title, '', '', '']);

        if (mb_strlen($out) > 160) {
            throw new QrPaymentFailed('ZBP payload longer than 160 characters.');
        }

        return $out;
    }
}
