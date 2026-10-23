<?php

declare(strict_types=1);

namespace App\Libraries\Platform;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Fixed instant for tests.
 */
final class FrozenClock extends Clock
{
    private readonly DateTimeImmutable $instant;

    public function __construct(string $instant, ?string $timezone = null)
    {
        parent::__construct($timezone);

        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $instant);
        if ($parsed === false) {
            throw new InvalidArgumentException('Invalid ISO 8601 instant: ' . $instant);
        }

        $this->instant = $parsed->setTimezone($this->timezone());
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}
