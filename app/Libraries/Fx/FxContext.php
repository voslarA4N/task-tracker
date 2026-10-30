<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

/**
 * Odvodene vstupy hladania kurzu: nastavenie krajiny, kotviaci datum a horna hranica (D-05).
 */
final readonly class FxContext
{
    public function __construct(
        public string $country,
        public string $source,
        public string $dateBasis,
        public string $dayRule,
        public string $anchor,
        public string $bound,
        public string $entityCurrency,
        public string $invoiceCurrency,
        public bool $supported,
        public string $direction,
    ) {
    }
}
