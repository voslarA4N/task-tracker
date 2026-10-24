<?php

declare(strict_types=1);

namespace App\Libraries\Platform;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 adapter okolo Clock (otphp inak hlasi deprecation pri chybajucich hodinach).
 */
final class PsrClock implements ClockInterface
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
