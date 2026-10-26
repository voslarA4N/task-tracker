<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use InvalidArgumentException;

/**
 * Stabilne formatovanie sum, hodin a datumov pre PDF: iba retazce, bez NumberFormatter a bez bcmath,
 * aby vystup nezavisel od verzie ICU ani od locale servera (D-17).
 */
final class PdfFormat
{
    private const NBSP    = "\u{00A0}";
    private const SYMBOLS = ['EUR' => '€', 'PLN' => 'zł', 'CZK' => 'Kč'];

    public static function amount(string $amount, string $currency, string $language): string
    {
        if (preg_match('/^-?\d{1,10}\.\d{2}$/D', $amount) !== 1) {
            throw new InvalidArgumentException('Invalid amount value.');
        }

        $sign        = $amount[0] === '-' ? '-' : '';
        [$int, $dec] = explode('.', ltrim($amount, '-'));

        $thousands = $language === 'en' ? ',' : self::NBSP;
        $decimal   = $language === 'en' ? '.' : ',';
        $grouped   = str_replace('|', $thousands, strrev(implode('|', str_split(strrev($int), 3))));

        return $sign . $grouped . $decimal . $dec . self::NBSP . (self::SYMBOLS[$currency] ?? $currency);
    }

    public static function hours(string $hours, string $language): string
    {
        if (preg_match('/^-?\d{1,5}\.\d{2}$/D', $hours) !== 1) {
            throw new InvalidArgumentException('Invalid hours value.');
        }

        return $language === 'en' ? $hours : str_replace('.', ',', $hours);
    }

    public static function date(string $ymd, string $language): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $ymd, $m) !== 1) {
            throw new InvalidArgumentException('Invalid date value.');
        }

        return match ($language) {
            'en'    => $ymd,
            'pl'    => $m[3] . '.' . $m[2] . '.' . $m[1],
            default => ((string) (int) $m[3]) . '. ' . ((string) (int) $m[2]) . '. ' . $m[1],
        };
    }
}
