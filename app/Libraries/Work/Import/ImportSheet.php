<?php

declare(strict_types=1);

namespace App\Libraries\Work\Import;

use App\Libraries\Work\BillingMonth;
use DomainException;
use InvalidArgumentException;

final readonly class ImportSheet
{
    /**
     * @param list<ImportBlock> $blocks
     */
    public function __construct(
        public ?string $rate,
        public array $blocks,
    ) {
    }

    /**
     * Ponecha len bloky daneho mesiaca (RRRR-MM). Kluce importu sa nemenia, lebo zaklad kluca obsahuje mesiac riadku.
     *
     * @throws DomainException          mesiac v subore nie je
     * @throws InvalidArgumentException neplatny format mesiaca
     */
    public function forMonth(string $month): self
    {
        if (BillingMonth::parse($month) === null) {
            throw new InvalidArgumentException('Neplatný mesiac, očakávaný formát RRRR-MM: ' . $month);
        }

        $filtered = array_values(array_filter($this->blocks, static fn (ImportBlock $b): bool => $b->month === $month));

        if ($filtered === []) {
            $found = array_values(array_unique(array_map(static fn (ImportBlock $b): string => $b->month, $this->blocks)));

            throw new DomainException('Mesiac ' . $month . ' v súbore nie je (nájdené: ' . implode(', ', $found) . ').');
        }

        return new self($this->rate, $filtered);
    }
}
