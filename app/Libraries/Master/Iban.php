<?php

declare(strict_types=1);

namespace App\Libraries\Master;

/**
 * IBAN: normalizacia, overenie (format, dlzka krajiny, mod-97) a formatovanie pre UI. Bez bcmath/GMP.
 */
final class Iban
{
    private const LENGTHS = ['SK' => 24, 'PL' => 28, 'CZ' => 24];

    public static function normalize(string $s): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/u', '', $s));
    }

    public static function isValid(string $normalized): bool
    {
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $normalized) !== 1) {
            return false;
        }

        $length = strlen($normalized);
        $expect = self::LENGTHS[substr($normalized, 0, 2)] ?? null;

        if ($expect !== null ? $length !== $expect : ($length < 15 || $length > 34)) {
            return false;
        }

        $digits = '';

        foreach (str_split(substr($normalized, 4) . substr($normalized, 0, 4)) as $c) {
            $digits .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
        }

        $rem = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $rem = (int) ($rem . $chunk) % 97;
        }

        return $rem === 1;
    }

    public static function format(string $normalized): string
    {
        return trim(implode(' ', str_split($normalized, 4)));
    }
}
