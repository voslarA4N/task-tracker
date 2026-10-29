<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed;

/**
 * Vysledok HistoryPlanner: cisty popis vymysleneho sveta. Moduly z neho citaju a mapuju kluce na id v DB.
 *
 * Datumy a casy su lokalne retazce (YYYY-MM-DD, YYYY-MM-DDTHH:MM:SS) v casovej zone aplikacie.
 */
final readonly class HistoryPlan
{
    /**
     * @param list<array<string, mixed>> $entities
     * @param list<array<string, mixed>> $clients
     * @param list<array<string, mixed>> $tasks
     * @param list<array<string, mixed>> $invoices
     * @param list<array<string, mixed>> $deliveries odoslania, rucne oznacenia, uhrady, storna uhrad a upomienky zoradene podla `at`
     */
    public function __construct(
        public string $today,
        public int $seed,
        public array $entities,
        public array $clients,
        public array $tasks,
        public array $invoices,
        public array $deliveries = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'today'      => $this->today,
            'seed'       => $this->seed,
            'entities'   => $this->entities,
            'clients'    => $this->clients,
            'tasks'      => $this->tasks,
            'invoices'   => $this->invoices,
            'deliveries' => $this->deliveries,
        ];
    }
}
