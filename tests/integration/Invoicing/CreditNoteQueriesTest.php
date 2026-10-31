<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\InvoiceQueries;
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
final class CreditNoteQueriesTest extends PgTestCase
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
     * @return array{0: int, 1: int, 2: int}
     */
    private function setupDocs(): array
    {
        $s    = $this->issueSetup(['parts' => [['40.00', '2026-10'], ['10.00', '2026-10']]]);
        $orig = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]], ['dueDate' => '2026-10-28']))->id;
        $plain = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]], ['dueDate' => '2026-10-28']))->id;
        $cn   = $this->issueCreditNote($orig);

        return [$orig, $cn->id, $plain];
    }

    /**
     * @param array{rows: list<array<string, mixed>>} $r
     *
     * @return list<int>
     */
    private function ids(array $r): array
    {
        $ids = array_map('intval', array_column($r['rows'], 'id'));
        sort($ids);

        return $ids;
    }

    public function testListFilters(): void
    {
        [$orig, $cn, $plain] = $this->setupDocs();
        $q                   = new InvoiceQueries($this->rw());

        $this->assertSame([$orig], $this->ids($q->list(100, 'cancelled')));
        $this->assertSame([$cn], $this->ids($q->list(100, 'credit_note')));

        $all = [$orig, $cn, $plain];
        sort($all);
        $this->assertSame($all, $this->ids($q->list(100, null)));
        $this->assertSame([$plain], $this->ids($q->list(100, 'overdue')));

        $rows = array_column($q->list(100, null)['rows'], null, 'id');
        $this->assertSame('credit_note', $rows[$cn]['type']);
        $this->assertSame('invoice', $rows[$orig]['type']);
        $this->assertNotNull($rows[$orig]['credit_note_number']);
        $this->assertNotNull($rows[$cn]['corrected_number']);
    }

    public function testDetail(): void
    {
        [$orig, $cn] = $this->setupDocs();
        $q           = new InvoiceQueries($this->rw());

        $d = $q->detail($orig);
        $this->assertSame('cancelled', $d['status']);
        $this->assertSame($cn, (int) $d['credit_note_id']);
        $this->assertNotNull($d['credit_note_number']);
        $this->assertNotNull($d['credit_note_issue_date']);

        $c = $q->detail($cn);
        $this->assertSame('credit_note', $c['type']);
        $this->assertSame($orig, (int) $c['corrected_invoice_id']);
        $this->assertNotNull($c['corrected_number']);
        $this->assertNotNull($c['corrected_issue_date']);
        $this->assertSame('Chybná sadzba', $c['correction_reason']);
        $this->assertSame('amount_error', $c['correction_type']);
        $this->assertNull($c['report_document']);
    }
}
