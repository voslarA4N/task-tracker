<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

/**
 * Cerpanie vsetkych limitov jedneho subjektu (vystup TurnoverCalculator::forEntity).
 */
final readonly class EntityLimits
{
    /**
     * @param list<LimitStatus> $statuses bez chyby podla ratio zostupne, potom chybove
     */
    public function __construct(
        public int $entityId,
        public string $entityName,
        public string $country,
        public string $currency,
        public int $refYear,
        public ?int $usedYear,
        public bool $noLimit,
        public array $statuses,
    ) {
    }

    /**
     * Prisnejsi limit = prvy status bez chyby (zoznam je zoradeny podla ratio).
     */
    public function strictest(): ?LimitStatus
    {
        foreach ($this->statuses as $status) {
            if ($status->error === null) {
                return $status;
            }
        }

        return null;
    }

    /**
     * @return list<LimitStatus>
     */
    public function alerts(): array
    {
        return array_values(array_filter($this->statuses, static fn (LimitStatus $s): bool => $s->isAlert()));
    }
}
