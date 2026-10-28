<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed;

use App\Libraries\Platform\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Meniteľne hodiny pre spatne datovanu historiu (D-03): moduly nastavia okamih pred kazdym volanim sluzby,
 * takze created_at, done_at, cisla faktur, dokumenty a audit nesu fiktivny cas.
 */
class SeedClock extends Clock
{
    private DateTimeImmutable $instant;

    public function __construct(string $initialLocalIso, ?string $timezone = null)
    {
        parent::__construct($timezone);

        $this->instant = $this->parse($initialLocalIso);
    }

    /**
     * @param string $localIso YYYY-MM-DDTHH:MM:SS v casovej zone aplikacie
     */
    public function at(string $localIso): void
    {
        $this->instant = $this->parse($localIso);
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    private function parse(string $iso): DateTimeImmutable
    {
        return $this->parseDateTime($iso) ?? throw new InvalidArgumentException('Invalid local ISO datetime: ' . $iso);
    }
}
