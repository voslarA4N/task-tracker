<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

/**
 * Cerpanie jedneho limitu obratu pre subjekt (vystup TurnoverCalculator).
 */
final readonly class LimitStatus
{
    /**
     * @param list<int> $thresholds
     * @param list<int> $openingYears
     */
    public function __construct(
        public int $limitId,
        public int $entityId,
        public string $name,
        public string $country,
        public int $limitYear,
        public int $refYear,
        public ?int $fallbackFromYear,
        public string $method,
        public array $thresholds,
        public string $periodFrom,
        public string $periodTo,
        public string $documentTurnover,
        public int $invoiceCount,
        public int $creditNoteCount,
        public string $openingTurnover,
        public array $openingYears,
        public string $total,
        public string $baseLimit,
        public string $limit,
        public ?int $proRataDays,
        public bool $proRataOutsideFirstYear,
        public string $percent,
        public string $ratio,
        public string $remaining,
        public string $exceededBy,
        public string $currency,
        public string $level,
        public ?string $error,
        public bool $isSeed,
    ) {
    }

    public function isAlert(): bool
    {
        return $this->error !== null || $this->level !== 'normal';
    }
}
