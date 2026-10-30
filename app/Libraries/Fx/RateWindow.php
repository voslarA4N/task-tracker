<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use InvalidArgumentException;

/**
 * Spolocna validacia vstupov do URL (SSRF, T-08-08) a okno [bound-10, bound]. Bez kalendara sviatkov (D-06).
 */
final class RateWindow
{
    public static function currency(string $currency): string
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('Invalid currency.');
        }

        return $currency;
    }

    /**
     * @return array{0: string, 1: string} [od, do]
     */
    public static function range(string $bound): array
    {
        $date = service('clock')->parseDate($bound);

        if ($date === null) {
            throw new InvalidArgumentException('Invalid bound date.');
        }

        return [$date->modify('-10 days')->format('Y-m-d'), $date->format('Y-m-d')];
    }
}
