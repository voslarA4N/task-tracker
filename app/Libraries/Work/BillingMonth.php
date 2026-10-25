<?php

declare(strict_types=1);

namespace App\Libraries\Work;

use App\Libraries\Platform\Clock;
use IntlCalendar;
use IntlDateFormatter;

/**
 * Fakturacny mesiac RRRR-MM. Ciste celociselna aritmetika (rok * 12 + mesiac), cas iba cez Clock.
 */
final class BillingMonth
{
    private const MIN_BACK    = 12;
    private const MAX_FORWARD = 24;

    public static function parse(string $raw): ?string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $raw) === 1 ? $raw : null;
    }

    public static function current(?Clock $clock = null): string
    {
        return ($clock ?? service('clock'))->now()->format('Y-m');
    }

    public static function addMonths(string $month, int $n): string
    {
        $index = self::index($month) + $n;
        $year  = intdiv($index, 12);

        return sprintf('%04d-%02d', $year, $index - $year * 12 + 1);
    }

    public static function next(string $month): string
    {
        return self::addMonths($month, 1);
    }

    public static function previous(string $month): string
    {
        return self::addMonths($month, -1);
    }

    public static function inRange(string $month, ?Clock $clock = null): bool
    {
        if (self::parse($month) === null) {
            return false;
        }

        $bounds = self::rangeBounds($clock);

        return $month >= $bounds['min'] && $month <= $bounds['max'];
    }

    /**
     * @return array{min: string, max: string}
     */
    public static function rangeBounds(?Clock $clock = null): array
    {
        $current = self::current($clock);

        return [
            'min' => self::addMonths($current, -self::MIN_BACK),
            'max' => self::addMonths($current, self::MAX_FORWARD),
        ];
    }

    public static function label(string $month, string $locale): string
    {
        return self::formatMonth($month, $locale, 'LLLL y');
    }

    public static function shortLabel(string $month, string $locale): string
    {
        return rtrim(self::formatMonth($month, $locale, 'LLL'), '.');
    }

    private static function index(string $month): int
    {
        return (int) substr($month, 0, 4) * 12 + (int) substr($month, 5, 2) - 1;
    }

    private static function formatMonth(string $month, string $locale, string $pattern): string
    {
        $calendar = IntlCalendar::createInstance('UTC', $locale . '@calendar=gregorian');
        $calendar->clear();
        $calendar->set((int) substr($month, 0, 4), (int) substr($month, 5, 2) - 1, 1);

        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', $calendar, $pattern);
        $text      = (string) $formatter->format($calendar);

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
