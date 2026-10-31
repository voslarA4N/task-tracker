<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
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
final class CreditNoteSchemaTest extends PgTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    /** @var array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int} */
    private array $s;
    private int $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();

        $this->s       = $this->issueSetup();
        $this->invoice = $this->issuer()->issue($this->issueCommand($this->s['client'], '2026-10', $this->s['entity'], $this->s['parts']))->id;
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function assertDbError(string $regex, callable $fn): void
    {
        try {
            $fn();
        } catch (DatabaseException $e) {
            $this->assertMatchesRegularExpression($regex, $e->getMessage());

            return;
        }

        $this->fail('Expected DatabaseException matching ' . $regex);
    }

    public function testCreditNoteIsInserted(): void
    {
        $id  = $this->insertRawCreditNote($this->invoice);
        $row = $this->owner()->query('SELECT type, total, corrected_invoice_id FROM invoices WHERE id = :i:', ['i' => $id])->getRow();

        $this->assertSame('credit_note', $row->type);
        $this->assertSame('-1080.00', $row->total);
        $this->assertSame($this->invoice, (int) $row->corrected_invoice_id);
    }

    public function testSecondCreditNoteIsRejected(): void
    {
        $this->insertRawCreditNote($this->invoice);

        $this->assertDbError('/invoices_one_credit_note_uq/', fn () => $this->insertRawCreditNote($this->invoice, ['number' => 'DB-TEST-2', 'request_key' => $this->uuid4()]));
    }

    public function testCreditNoteFieldsAreValidated(): void
    {
        $this->assertDbError('/invoices_credit_note_fields_check/', fn () => $this->insertRawCreditNote($this->invoice, ['correction_reason' => '   ']));
        $this->assertDbError('/invoices_credit_note_fields_check/', fn () => $this->insertRawCreditNote($this->invoice, ['correction_type' => null]));
        $this->assertDbError('/invoices_credit_note_fields_check|invoices_origin_shape/', fn () => $this->insertRawCreditNote($this->invoice, ['origin' => 'external']));
        $this->assertDbError('/invoices_credit_note_total_check/', fn () => $this->insertRawCreditNote($this->invoice, ['total' => '1080.00']));
    }

    public function testTargetMustBeOwnIssuedInvoiceOfSameClient(): void
    {
        $cn = $this->insertRawCreditNote($this->invoice);

        $this->assertDbError('/is not creditable/', fn () => $this->insertRawCreditNote($this->invoice, ['corrected_invoice_id' => $cn, 'number' => 'DB-TEST-X', 'request_key' => $this->uuid4()]));

        $alloc    = $this->createAllocation($this->createTask($this->s['client']), '2.00', '2026-09');
        $external = $this->insertExternalInvoice($this->s['client'], '2026-09', [$alloc], 'EXT-1');
        $this->assertDbError('/is not creditable/', fn () => $this->insertRawCreditNote($this->invoice, ['corrected_invoice_id' => $external, 'number' => 'DB-TEST-Y', 'request_key' => $this->uuid4()]));

        $otherClient = $this->createClient();
        $this->assertDbError('/is not creditable/', fn () => $this->insertRawCreditNote($this->invoice, ['client_id' => $otherClient, 'number' => 'DB-TEST-Z', 'request_key' => $this->uuid4()]));
    }

    public function testCreditNoteDoesNotNeedReportButInvoicePdfIsRequired(): void
    {
        $this->assertGreaterThan(0, $this->insertRawCreditNote($this->invoice));

        $this->owner()->query('SELECT 1');
        $this->assertDbError('/is incomplete/', fn () => $this->insertRawCreditNote($this->invoice, ['number' => 'DB-TEST-N', 'request_key' => $this->uuid4(), 'without_document' => true, 'correction_type' => 'other']));
    }

    public function testInvoiceWithoutReportStillFails(): void
    {
        $alloc = $this->createAllocation($this->createTask($this->s['client']), '2.00', '2026-09');

        $this->assertDbError('/is incomplete/', fn () => $this->insertIssuedInvoiceRaw($this->s['client'], $this->s['entity'], '2026-09', [$alloc], '%PDF-1.4 test', ['number' => 'NOREPORT-1', 'snapshot_schema' => 2]));
    }
}
