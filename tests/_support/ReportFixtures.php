<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Platform\Uuid;
use App\Libraries\Receivables\PaymentService;

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

    /**
     * Uhrada cez PaymentService (vyzaduje zmrazene hodiny po paidOn).
     */
    protected function reportPayment(int $invoiceId, string $paidOn, string $amount): int
    {
        return (new PaymentService($this->rw()))->record($invoiceId, $amount, $paidOn, null, Uuid::v4(), null)->id;
    }

    protected function voidPayment(int $paymentId): void
    {
        (new PaymentService($this->rw()))->void($paymentId, 'Test storno', null);
    }
}
