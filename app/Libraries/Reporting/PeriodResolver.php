<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use App\Libraries\Platform\Clock;
use DateTimeImmutable;
use InvalidArgumentException;
use RoundingMode;

/**
 * Dátumové okná limitov obratu (calendar_year, rolling_12m, pro_rata_first_year) a pomerný limit.
 */
final class PeriodResolver
{
    public const METHODS = ['calendar_year', 'rolling_12m', 'pro_rata_first_year'];

    public const PRO_RATA_BASE_DAYS = 365;

    public static function resolve(string $method, string $refDate, ?string $activeFrom): LimitPeriod
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Unknown limit method: ' . $method);
        }

        $ref  = self::parse($refDate);
        $year = (int) $ref->format('Y');

        if ($method === 'rolling_12m') {
            $month = (int) $ref->format('n');
            // Skladanie z celych cisel (rok, mesiac): bez pretecenia 31. dna.
            $index     = $year * 12 + ($month - 1) - 11;
            $fromYear  = intdiv($index, 12);
            $fromMonth = $index % 12 + 1;
            $from      = sprintf('%04d-%02d-01', $fromYear, $fromMonth);
            $to        = $ref->modify('last day of this month')->format('Y-m-d');

            return new LimitPeriod($from, $to, range($fromYear, $year), null, false);
        }

        if ($method === 'pro_rata_first_year') {
            if ($activeFrom !== null) {
                $start = self::parse($activeFrom);
                if ((int) $start->format('Y') === $year) {
                    $end = self::parse($year . '-12-31');
                    // [overiť] s účtovníčkou, D-03: počet dní sa obmedzí na 365 aj v prestupnom roku.
                    $days = min($start->diff($end)->days + 1, self::PRO_RATA_BASE_DAYS);

                    return new LimitPeriod($start->format('Y-m-d'), $year . '-12-31', [$year], $days, false);
                }
            }

            return new LimitPeriod($year . '-01-01', $year . '-12-31', [$year], null, true);
        }

        return new LimitPeriod($year . '-01-01', $year . '-12-31', [$year], null, false);
    }

    public static function proRataLimit(string $amount, int $days): string
    {
        if ($days < 1 || $days > self::PRO_RATA_BASE_DAYS) {
            throw new InvalidArgumentException('Pro-rata days out of range: ' . $days);
        }

        return bcround(
            bcdiv(bcmul($amount, (string) $days, 2), (string) self::PRO_RATA_BASE_DAYS, 6),
            2,
            RoundingMode::HalfAwayFromZero,
        );
    }

    private static function parse(string $iso): DateTimeImmutable
    {
        $date = (new Clock('UTC'))->parseDate($iso);
        if ($date === null) {
            throw new InvalidArgumentException('Invalid date: ' . $iso);
        }

        return $date;
    }
}
