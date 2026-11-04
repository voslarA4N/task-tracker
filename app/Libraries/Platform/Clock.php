<?php

declare(strict_types=1);

namespace App\Libraries\Platform;

use Config\App;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * The only source of current time in the application (service('clock')).
 */
class Clock
{
    private readonly string $timezoneName;

    public function __construct(?string $timezone = null)
    {
        $this->timezoneName = $timezone ?? config(App::class)->appTimezone;
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone());
    }

    /**
     * Prisne parsuje absolutny okamih s casovou zonou (napr. timestamptz z PG '2026-11-01 10:00:00+01') bez citania hodin.
     * Prazdny, relativny ('now', 'today') alebo bezzonovy retazec vyhodi vynimku, nikdy nevrati "teraz".
     *
     * @throws InvalidArgumentException
     */
    public static function parseInstant(string $value): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}(:?\d{2})?|Z)$/D', $value) !== 1) {
            throw new InvalidArgumentException('Not an absolute timestamptz: ' . $value);
        }

        try {
            $at = new DateTimeImmutable($value);
        } catch (Exception $e) {
            throw new InvalidArgumentException('Not an absolute timestamptz: ' . $value, 0, $e);
        }

        // PHP pretaca neexistujuci den (2026-02-31 -> 2026-03-03) len varovanim
        $errors = DateTimeImmutable::getLastErrors();

        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('Not an absolute timestamptz: ' . $value);
        }

        return $at;
    }

    public function today(): string
    {
        return $this->now()->format('Y-m-d');
    }

    /**
     * Prísny parser kalendárneho dátumu YYYY-MM-DD (polnoc UTC), null pri neplatnom (aj 2026-02-30).
     */
    public function parseDate(string $iso): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $iso ? $date : null;
    }

    /**
     * Prisny parser lokalneho datumu a casu YYYY-MM-DDTHH:MM:SS v casovej zone aplikacie, null pri neplatnom.
     * Pouziva ho staging seed na spatne datovanu historiu.
     */
    public function parseDateTime(string $iso): ?DateTimeImmutable
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $iso, $this->timezone());

        return $at !== false && $at->format('Y-m-d\TH:i:s') === $iso ? $at : null;
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->timezoneName);
    }
}
