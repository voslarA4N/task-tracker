<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

/**
 * Vysledok prehladu za obdobie a subjekt. Sumy su v mene subjektu (total_entity_ccy).
 * invoiced = vsetky vystavene doklady, counted = iba tie, ktore sa pocitaju do obratu (D-05).
 */
final readonly class ReportResult
{
    /**
     * @param list<array{client_id: int, client_name: string, invoiced: string, counted: string}> $clients
     * @param list<array{month: string, invoiced: string, counted: string}>                       $months
     */
    public function __construct(
        public int $entityId,
        public string $currency,
        public ReportPeriod $period,
        public array $clients,
        public array $months,
        public string $invoicedTotal,
        public string $countedTotal,
        public int $invoiceCount,
        public int $creditNoteCount,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->invoiceCount + $this->creditNoteCount === 0;
    }
}
