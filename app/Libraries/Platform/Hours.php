<?php

declare(strict_types=1);

namespace App\Libraries\Platform;

use InvalidArgumentException;
use RoundingMode;

/**
 * Hodiny ako desatinny string (numeric(7,2)), iba bcmath, nikdy float. Kazde bc* ma explicitny scale.
 */
final class Hours
{
    public static function of(string $raw): string
    {
        $clean = preg_replace('/[\s\x{00A0}]+/u', '', $raw);
        $clean = str_replace(',', '.', $clean ?? '');

        if (preg_match('/^\d{1,5}(\.\d{1,2})?$/D', $clean) !== 1) {
            throw new InvalidArgumentException('Invalid hours value.');
        }

        return bcadd($clean, '0', 2);
    }

    public static function plus(string $a, string $b): string
    {
        return bcadd($a, $b, 2);
    }

    public static function minus(string $a, string $b): string
    {
        return bcsub($a, $b, 2);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, 2);
    }

    public static function isPositive(string $h): bool
    {
        return bccomp($h, '0', 2) > 0;
    }

    public static function remaining(string $quoted, string $allocated): string
    {
        $left = bcsub($quoted, $allocated, 2);

        return bccomp($left, '0', 2) < 0 ? '0.00' : $left;
    }

    public static function delta(string $allocated, string $quoted): string
    {
        return bcsub($allocated, $quoted, 2);
    }

    /**
     * Cele percento zaokruhlene half-up na nasobok $step, orezane na 0..100.
     */
    public static function percentOf(string $part, string $total, int $step = 5): int
    {
        if (bccomp($total, '0', 2) <= 0 || bccomp($part, '0', 2) <= 0) {
            return 0;
        }

        $percent = bcdiv(bcmul($part, '100', 4), $total, 4);

        if (bccomp($percent, '100', 4) >= 0) {
            return 100;
        }

        $steps = bcround(bcdiv($percent, (string) $step, 4), 0, RoundingMode::HalfAwayFromZero);

        return min(100, (int) $steps * $step);
    }

    public static function format(string $hours, string $locale): string
    {
        $value              = bcadd($hours, '0', 2);
        $sign               = str_starts_with($value, '-') ? '-' : '';
        [$whole, $fraction] = explode('.', ltrim($value, '-')) + [1 => '00'];
        $fraction           = rtrim($fraction, '0');

        return $sign . $whole . ($fraction === '' ? '' : self::separator($locale) . $fraction);
    }

    public static function formatSigned(string $delta, string $locale): string
    {
        $value = bcadd($delta, '0', 2);

        if (bccomp($value, '0', 2) === 0) {
            return '0';
        }

        if (bccomp($value, '0', 2) > 0) {
            return '+' . self::format($value, $locale);
        }

        return "\u{2212}" . self::format(ltrim($value, '-'), $locale);
    }

    private static function separator(string $locale): string
    {
        return str_starts_with(strtolower($locale), 'en') ? '.' : ',';
    }
}
