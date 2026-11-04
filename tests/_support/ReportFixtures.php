<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Fixtures prehladov (Phase 11) nad TurnoverFixtures. Pouzivajuci test musi pouzivat aj MasterFixtures,
 * InvoiceFixtures, CreditNoteFixtures, IssueFixtures a TurnoverFixtures.
 *
 * @mixin PgTestCase
 */
trait ReportFixtures
{
    /**
     * @param array<string, mixed> $overrides
     */
    protected function reportInvoice(int $entityId, int $clientId, string $turnoverDate, string $amount, array $overrides = []): int
    {
        return $this->turnoverInvoice($entityId, $clientId, $turnoverDate, $amount, $overrides);
    }

    /**
     * Klient s counts_toward_limit = false (jeho faktury sa nezapocitavaju do obratu).
     */
    protected function clientOutsideLimit(string $name = 'Mimo limit'): int
    {
        return $this->createClient(['name' => $name, 'identifier_value' => '87654321', 'counts_toward_limit' => false]);
    }
}
