<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Invoicing\CreditNoteRejected;
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
final class CreditNoteIssuerTest extends PgTestCase
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

    /**
     * @return array{0: array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int}, 1: int}
     */
    private function issuedInvoice(): array
    {
        $s  = $this->issueSetup(['parts' => [['40.00', '2026-10'], ['10.00', '2026-10']]]);
        $id = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;

        return [$s, $id];
    }

    public function testIssuesCreditNoteWithAllColumns(): void
    {
        [$s, $orig] = $this->issuedInvoice();
        $before     = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $orig])->getRowArray();

        $cn = $this->issueCreditNote($orig);

        $this->assertMatchesRegularExpression('/^DB-202610\d{3}$/', $cn->number);
        $this->assertSame(2, $cn->releasedParts);
        $this->assertSame($orig, $cn->originalId);
        $this->assertSame('-' . $before['total'], $cn->total);

        $row = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $cn->id])->getRowArray();
        $this->assertSame('credit_note', $row['type']);
        $this->assertSame('issued', $row['origin']);
        $this->assertSame($orig, (int) $row['corrected_invoice_id']);
        $this->assertSame('Chybná sadzba', $row['correction_reason']);
        $this->assertSame('amount_error', $row['correction_type']);
        $creditSeries = (int) $this->owner()->query("SELECT id FROM invoice_series WHERE entity_id = :e: AND kind = 'credit_note'", ['e' => $s['entity']])->getRow()->id;
        $this->assertSame($creditSeries, (int) $row['series_id']);
        $this->assertSame($row['issue_date'], $row['due_date']);

        foreach (['delivery_date', 'turnover_date', 'billing_month', 'counts_toward_limit', 'client_id', 'entity_id', 'entity_version_id', 'currency', 'doc_language'] as $col) {
            $this->assertSame($before[$col], $row[$col], $col);
        }
        $this->assertSame('cn-1', $row['template_version']);
        $this->assertSame(4, (int) $row['snapshot_schema']);

        $this->assertSumsToZero($orig, $cn->id);

        $lines = $this->owner()->query('SELECT * FROM invoice_lines WHERE invoice_id = :i: ORDER BY position', ['i' => $cn->id])->getResultArray();
        $this->assertCount(2, $lines);

        foreach ($lines as $line) {
            $this->assertStringStartsWith('-', (string) $line['hours']);
            $this->assertNull($line['source_allocation_id']);
        }

        $docs = $this->owner()->query('SELECT kind, sha256 FROM invoice_documents WHERE invoice_id = :i:', ['i' => $cn->id])->getResultArray();
        $this->assertCount(1, $docs);
        $this->assertSame('invoice', $docs[0]['kind']);
        $this->assertSame($cn->sha256, trim((string) $docs[0]['sha256']));

        foreach ($s['parts'] as $part) {
            $this->assertNull($this->owner()->query('SELECT invoice_id FROM billing_allocations WHERE id = :i:', ['i' => $part])->getRow()->invoice_id);
        }

        $audit = $this->owner()->query("SELECT after_data FROM audit_log WHERE action = 'credit_note_issue'")->getResultArray();
        $this->assertCount(1, $audit);
        $data = json_decode((string) $audit[0]['after_data'], true, 512, JSON_THROW_ON_ERROR);

        foreach (['number', 'corrected_invoice_id', 'corrected_number', 'reason', 'correction_type', 'total', 'currency', 'released_parts', 'sha256'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }

    public function testSameRequestKeyIsAlreadyIssued(): void
    {
        [, $orig] = $this->issuedInvoice();
        $key      = $this->uuid4();
        $cn       = $this->issueCreditNote($orig, ['requestKey' => $key]);

        try {
            $this->issueCreditNote($orig, ['requestKey' => $key]);
            $this->fail('Expected already_issued.');
        } catch (CreditNoteRejected $e) {
            $this->assertSame('already_issued', $e->reason);
            $this->assertSame($cn->id, $e->creditNoteId);
        }

        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM invoices WHERE type = 'credit_note'")->getRow()->c);
    }

    public function testStaleTokenIsRejected(): void
    {
        [, $orig] = $this->issuedInvoice();
        $token    = $this->creditNoteCommand($orig, ['reason' => 'Iny dovod'])->previewToken;

        try {
            $this->issueCreditNote($orig, ['previewToken' => $token]);
            $this->fail('Expected preview_stale.');
        } catch (CreditNoteRejected $e) {
            $this->assertSame('preview_stale', $e->reason);
        }
    }

    public function testNumberPeriodFollowsCreditNoteDate(): void
    {
        [, $orig] = $this->issuedInvoice();
        Services::injectMock('clock', new FrozenClock('2026-11-02T10:00:00+01:00'));

        $cn = $this->issueCreditNote($orig, ['issueDate' => '2026-11-02', 'correctionType' => CorrectionType::Other]);

        $this->assertMatchesRegularExpression('/^DB-202611\d{3}$/', $cn->number);
    }
}
