<?php

declare(strict_types=1);

namespace Tests\Integration\Receivables;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Uuid;
use App\Libraries\Receivables\InvoiceStatusQueries;
use App\Libraries\Receivables\PaymentRejected;
use App\Libraries\Receivables\PaymentService;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class CreditNoteReceivablesTest extends PgTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function issuedInvoice(): int
    {
        $s = $this->issueSetup(['parts' => [['40.00', '2026-10'], ['10.00', '2026-10']]]);

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['dueDate' => '2026-10-28']))->id;
    }

    private function rejection(callable $fn): string
    {
        try {
            $fn();
        } catch (PaymentRejected $e) {
            return $e->reason;
        }

        $this->fail('Expected PaymentRejected');
    }

    public function testCancelledInvoiceDisappearsFromOverdue(): void
    {
        $id = $this->issuedInvoice();
        $q  = new InvoiceStatusQueries($this->rw());

        $this->assertSame([$id], array_column($q->overdue('2026-10-31', null, 10)['rows'], 'id'));
        $this->assertSame(1, $q->countOverdue('2026-10-31', null));

        $cn = $this->issueCreditNote($id);

        $rows = array_column($q->overdue('2026-10-31', null, 10)['rows'], 'id');
        $this->assertNotContains($id, $rows);
        $this->assertNotContains($cn->id, $rows);
        $this->assertSame(0, $q->countOverdue('2026-10-31', null));

        $state = $q->forInvoice($id, '2026-10-31');
        $this->assertSame('cancelled', $state['status']);
        $this->assertFalse($state['overdue']);
        $this->assertFalse($q->forInvoice($cn->id, '2026-10-31')['overdue']);
    }

    public function testPaymentsRejectedOnCancelledAndCreditNote(): void
    {
        $id  = $this->issuedInvoice();
        $cn  = $this->issueCreditNote($id);
        $svc = new PaymentService($this->rw());

        $this->assertSame('cancelled', $this->rejection(static fn () => $svc->record($id, '10.00', '2026-10-31', null, Uuid::v4(), null)));
        $this->assertSame('not_payable', $this->rejection(static fn () => $svc->record($cn->id, '10.00', '2026-10-31', null, Uuid::v4(), null)));

        $impact = $svc->impact($id, '10.00');
        $this->assertSame('0.00', $impact['amount']);
        $this->assertSame('0.00', $impact['balance_after']);
        $this->assertSame('cancelled', $impact['status_after']);
    }

    public function testExistingPaymentStaysButCannotBeVoided(): void
    {
        $id  = $this->issuedInvoice();
        $svc = new PaymentService($this->rw());
        $svc->record($id, '100.00', '2026-10-30', null, Uuid::v4(), null);
        $this->issueCreditNote($id);

        $q        = new InvoiceStatusQueries($this->rw());
        $payments = $q->payments($id);
        $this->assertCount(1, $payments);
        $this->assertSame('100.00', $q->forInvoice($id, '2026-10-31')['paid_amount']);

        $this->assertSame('cancelled', $this->rejection(static fn () => $svc->void($payments[0]['id'], 'Omyl', null)));
        $this->assertFalse($q->payments($id)[0]['voided']);
    }
}
