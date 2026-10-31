<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Work\AllocationService;
use App\Libraries\Work\TaskQueries;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * Faktura -> dobropis -> presun uvolnenych casti -> nova faktura (D-12, D-13).
 *
 * @internal
 */
#[Group('integration')]
final class CreditNoteReissueTest extends PgTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @return array{0: array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int}, 1: int, 2: int}
     */
    private function issueAndCredit(): array
    {
        $s  = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.50', '2026-10']]]);
        $f1 = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;

        Services::injectMock('clock', new FrozenClock('2026-11-03T10:00:00+01:00'));

        $cn = $this->issueCreditNote($f1);

        return [$s, $f1, $cn->id];
    }

    public function testCreditNoteMoveToOtherMonthAndReissue(): void
    {
        [$s, $f1, $cn] = $this->issueAndCredit();
        $alloc         = new AllocationService($this->rw(), new AuditLogger($this->rw()));

        foreach ($s['parts'] as $p) {
            $this->assertTrue($alloc->moveMonth($p, '2026-11'));
        }

        $f2 = $this->issuer()->issue($this->issueCommand($s['client'], '2026-11', $s['entity'], $s['parts'], ['issueDate' => '2026-11-03', 'deliveryDate' => '2026-11-03', 'dueDate' => '2026-11-13']));

        foreach ($s['parts'] as $p) {
            $this->assertSame($f2->id, (int) $this->owner()->query('SELECT invoice_id FROM billing_allocations WHERE id = :i:', ['i' => $p])->getRow()->invoice_id);
        }

        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations WHERE invoice_id = :i:', ['i' => $f1])->getRow()->c);
        $this->assertSame('cancelled', $this->owner()->query('SELECT status FROM invoice_status_v WHERE id = :i:', ['i' => $f1])->getRow()->status);
        $this->assertSame('issued', $this->owner()->query('SELECT status FROM invoice_status_v WHERE id = :i:', ['i' => $f2->id])->getRow()->status);

        $this->assertSumsToZero($f1, $cn);
        $this->assertSame((string) $this->owner()->query('SELECT total FROM invoices WHERE id = :i:', ['i' => $f1])->getRow()->total, $f2->total);

        // task_billing_v / TaskQueries: vyfakturovane hodiny nie su zdvojene
        $queries = new TaskQueries($this->rw());

        foreach ([['10.00', $s['tasks'][0]], ['5.50', $s['tasks'][1]]] as [$hours, $task]) {
            $v = $this->owner()->query('SELECT * FROM task_billing_v WHERE task_id = :t:', ['t' => $task])->getRow();
            $this->assertSame($hours, (string) $v->billed_hours);
            $this->assertSame(1, (int) $v->locked_count);

            $detail = $queries->detail($task);
            $this->assertNotNull($detail);
            $this->assertSame($hours, (string) $detail['billed_hours']);
            $this->assertSame($f2->id, (int) $detail['allocations'][0]['invoice_id']);
        }

        // historia F1: riadky stale odkazuju na povodne casti (D-13)
        $src = array_map(static fn (object $r): int => (int) $r->source_allocation_id, $this->owner()->query('SELECT source_allocation_id FROM invoice_lines WHERE invoice_id = :i: ORDER BY position', ['i' => $f1])->getResult());
        $this->assertSame($s['parts'], $src);
    }

    public function testReleasedPartsCanBeReissuedInSameMonth(): void
    {
        [$s, $f1, $cn] = $this->issueAndCredit();

        $f2 = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-11-03', 'deliveryDate' => '2026-10-31', 'dueDate' => '2026-11-13']));

        foreach ($s['parts'] as $p) {
            $this->assertSame($f2->id, (int) $this->owner()->query('SELECT invoice_id FROM billing_allocations WHERE id = :i:', ['i' => $p])->getRow()->invoice_id);
        }

        $this->assertSumsToZero($f1, $cn);
        $this->assertSame('cancelled', $this->owner()->query('SELECT status FROM invoice_status_v WHERE id = :i:', ['i' => $f1])->getRow()->status);
    }
}
