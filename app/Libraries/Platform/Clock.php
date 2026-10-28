<?php

declare(strict_types=1);

namespace App\Libraries\Platform;

use Config\App;
use DateTimeImmutable;
use DateTimeZone;

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
