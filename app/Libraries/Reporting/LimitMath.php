<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use App\Libraries\Platform\Money;
use InvalidArgumentException;
use RoundingMode;

/**
 * Aritmetika prahov a percent limitu obratu nad bcmath retazcami (bez float).
 */
final class LimitMath
{
    public static function percent(string $total, string $limit): string
    {
        return bcround(bcdiv(bcmul($total, '100', 4), $limit, 6), 1, RoundingMode::HalfAwayFromZero);
    }

    public static function ratio(string $total, string $limit): string
    {
        return bcdiv($total, $limit, 8);
    }

    /**
     * Porovnanie dvoch pomerov (retazce z ratio()), zostupne zoradenie pouziva zaporny vysledok.
     */
    public static function compareRatio(string $a, string $b): int
    {
        return bccomp($a, $b, 8);
    }

    public static function reached(string $total, string $limit, int $threshold): bool
    {
        return bccomp(bcmul($total, '100', 4), bcmul((string) $threshold, $limit, 4), 4) >= 0;
    }

    /**
     * @param list<int> $thresholds
     *
     * @return list<int>
     */
    public static function reachedThresholds(string $total, string $limit, array $thresholds): array
    {
        sort($thresholds);
        $out = [];

        foreach ($thresholds as $threshold) {
            if (self::reached($total, $limit, $threshold)) {
                $out[] = $threshold;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $thresholds
     */
    public static function level(string $total, string $limit, array $thresholds): string
    {
        $reached = self::reachedThresholds($total, $limit, $thresholds);
        if ($reached === []) {
            return 'normal';
        }

        return max($reached) === max($thresholds) ? 'crit' : 'warn';
    }

    public static function remaining(string $total, string $limit): string
    {
        $diff = Money::of($limit)->minus(Money::of($total));

        return $diff->compare(Money::of('0.00')) > 0 ? $diff->toDecimalString() : '0.00';
    }

    public static function exceededBy(string $total, string $limit): string
    {
        $diff = Money::of($total)->minus(Money::of($limit));

        return $diff->compare(Money::of('0.00')) > 0 ? $diff->toDecimalString() : '0.00';
    }

    public static function formatPercent(string $percent, string $locale): string
    {
        if (preg_match('/^-?\d+\.\d$/D', $percent) !== 1) {
            throw new InvalidArgumentException('Invalid percent: ' . $percent);
        }

        return $locale === 'en' ? $percent : str_replace('.', ',', $percent);
    }
}
