<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

/**
 * Obdobie, za ktore sa pocita obrat voci limitu (from a to su Y-m-d, vratane).
 */
final readonly class LimitPeriod
{
    /**
     * @param list<int> $openingYears roky, ktorych pociatocny obrat sa pripocitava
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $openingYears,
        public ?int $proRataDays,
        public bool $proRataOutsideFirstYear,
    ) {
    }
}
