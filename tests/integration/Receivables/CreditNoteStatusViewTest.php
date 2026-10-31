<?php

declare(strict_types=1);

namespace Tests\Integration\Receivables;

use App\Libraries\Platform\FrozenClock;
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
final class CreditNoteStatusViewTest extends PgTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private int $invoice;
    private int $client;
    private int $entity;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();

        $s             = $this->issueSetup();
        $this->client  = $s['client'];
        $this->entity  = $s['entity'];
        $this->invoice = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        return $this->owner()->query('SELECT * FROM invoice_status_v WHERE id = :i:', ['i' => $id])->getRowArray() ?? [];
    }

    public function testInvoiceWithoutCreditNoteKeepsStatus(): void
    {
        $row = $this->row($this->invoice);

        $this->assertSame('issued', $row['status']);
        $this->assertNull($row['credit_note_id']);
        $this->assertSame('1080.00', $row['balance']);
    }

    public function testCreditNoteMakesInvoiceCancelled(): void
    {
        $cn  = $this->insertRawCreditNote($this->invoice);
        $row = $this->row($this->invoice);

        $this->assertSame('cancelled', $row['status']);
        $this->assertSame('0.00', $row['balance']);
        $this->assertSame($cn, (int) $row['credit_note_id']);
        $this->assertSame('DB-TEST-' . $this->invoice, $row['credit_note_number']);
        $this->assertSame($this->owner()->query('SELECT issue_date FROM invoices WHERE id = :i:', ['i' => $cn])->getRow()->issue_date, $row['credit_note_issue_date']);
    }

    public function testPartiallyPaidInvoiceIsCancelledWithKeptPaidAmount(): void
    {
        $this->owner()->query('INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, :d:, 300.00, now())', ['i' => $this->invoice, 'd' => '2026-10-28']);
        $this->insertRawCreditNote($this->invoice);
        $row = $this->row($this->invoice);

        $this->assertSame('cancelled', $row['status']);
        $this->assertSame('300.00', $row['paid_amount']);
        $this->assertSame('0.00', $row['balance']);
    }

    public function testSentInvoiceIsCancelledAndKeepsSentAt(): void
    {
        $this->owner()->query("INSERT INTO invoice_sends (invoice_id, kind, sent_at, transport, counts_as_sent, created_at) VALUES (:i:, 'invoice', now(), 'smtp', true, now())", ['i' => $this->invoice]);
        $this->insertRawCreditNote($this->invoice);
        $row = $this->row($this->invoice);

        $this->assertSame('cancelled', $row['status']);
        $this->assertNotNull($row['sent_at']);
    }

    public function testCreditNoteRow(): void
    {
        $cn  = $this->insertRawCreditNote($this->invoice);
        $row = $this->row($cn);
        $orig = $this->owner()->query('SELECT number, issue_date FROM invoices WHERE id = :i:', ['i' => $this->invoice])->getRow();

        $this->assertSame('credit_note', $row['type']);
        $this->assertSame('issued', $row['status']);
        $this->assertSame('0.00', $row['balance']);
        $this->assertSame($this->invoice, (int) $row['corrected_invoice_id']);
        $this->assertSame($orig->number, $row['corrected_number']);
        $this->assertSame($orig->issue_date, $row['corrected_issue_date']);
        $this->assertNull($row['credit_note_id']);
    }

    public function testExternalInvoiceStaysExternal(): void
    {
        $alloc    = $this->createAllocation($this->createTask($this->client), '2.00', '2026-09');
        $external = $this->insertExternalInvoice($this->client, '2026-09', [$alloc], 'EXT-1');

        $this->assertSame('external', $this->row($external)['status']);
    }
}
