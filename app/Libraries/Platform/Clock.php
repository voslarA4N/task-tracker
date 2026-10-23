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

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->timezoneName);
    }
}
