<?php

declare(strict_types=1);

namespace Tests\Integration\Work;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Work\TaskRejected;
use App\Libraries\Work\TaskService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class MarkDoneServiceTest extends PgTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function service(): TaskService
    {
        return new TaskService($this->rw(), new AuditLogger($this->rw()));
    }

    private function audits(string $type, string $action): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM audit_log WHERE entity_type = ? AND action = ?', [$type, $action])->getRow()->c;
    }

    private function allocationCount(int $task): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations WHERE task_id = ?', [$task])->getRow()->c;
    }

    private function state(int $task): object
    {
        return $this->owner()->query('SELECT * FROM task_billing_v WHERE task_id = ?', [$task])->getRow();
    }

    public function testRemainingHoursIsQuotedMinusAllocations(): void
    {
        $task = $this->createTask($this->createClient());
        $this->assertSame('40.00', $this->service()->remainingHours($task));

        $this->createAllocation($task, '16.00', '2026-10');
        $this->assertSame('24.00', $this->service()->remainingHours($task));

        $this->createAllocation($task, '30.00', '2026-11');
        $this->assertSame('0.00', $this->service()->remainingHours($task));
    }

    public function testMarkDoneCreatesStatusAllocationAndAudits(): void
    {
        $task = $this->createTask($this->createClient());
        $aid  = $this->service()->markDone($task, '2026-10', '40.00');

        $this->assertNotNull($aid);
        $row = $this->service()->find($task);
        $this->assertSame('done', $row['status']);
        $this->assertSame('2026-10-25 08:00:00+00', (string) $this->owner()->query("SELECT to_char(done_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00') AS d FROM tasks WHERE id = ?", [$task])->getRow()->d);

        $alloc = $this->owner()->query('SELECT * FROM billing_allocations WHERE id = ?', [$aid])->getRow();
        $this->assertSame('40.00', (string) $alloc->hours);
        $this->assertSame('2026-10', (string) $alloc->billing_month);
        $this->assertSame('27.00', (string) $alloc->rate);
        $this->assertSame('1080.00', (string) $alloc->amount);
        $this->assertSame(1, $this->audits('task', 'mark_done'));
        $this->assertSame(1, $this->audits('billing_allocation', 'create'));
    }

    public function testZeroHoursMarksDoneWithoutAllocation(): void
    {
        $task = $this->createTask($this->createClient());

        $this->assertNull($this->service()->markDone($task, '2026-11', '0.00'));
        $this->assertSame('done', $this->service()->find($task)['status']);
        $this->assertSame(0, $this->allocationCount($task));
        $this->assertSame(1, $this->audits('task', 'mark_done'));
    }

    public function testHoursAboveRemainderAreNotBlocked(): void
    {
        $task = $this->createTask($this->createClient());
        $this->createAllocation($task, '16.00', '2026-09');

        $this->assertNotNull($this->service()->markDone($task, '2026-10', '30.00'));
        $this->assertSame(2, $this->allocationCount($task));
    }

    public function testSecondMarkDoneIsRejectedWithoutSecondAllocation(): void
    {
        $task = $this->createTask($this->createClient());
        $this->service()->markDone($task, '2026-10', '40.00');

        try {
            $this->service()->markDone($task, '2026-10', '40.00');
            $this->fail('Expected TaskRejected');
        } catch (TaskRejected $e) {
            $this->assertSame('already_done', $e->reason);
        }

        $this->assertSame(1, $this->allocationCount($task));
        $this->assertSame(1, $this->audits('task', 'mark_done'));
    }

    public function testFailingAllocationRollsEverythingBack(): void
    {
        $task = $this->createTask($this->createClient());

        try {
            $this->service()->markDone($task, '2026-13', '10.00');
            $this->fail('Expected DatabaseException');
        } catch (DatabaseException) {
            // CHECK na billing_month
        }

        $row = $this->service()->find($task);
        $this->assertSame('in_progress', $row['status']);
        $this->assertNull($row['done_at']);
        $this->assertSame(0, $this->allocationCount($task));
        $this->assertSame(0, $this->audits('task', 'mark_done'));
    }

    public function testReopenClearsDoneAtAndKeepsAllocations(): void
    {
        $task = $this->createTask($this->createClient());
        $this->service()->markDone($task, '2026-10', '40.00');
        $this->service()->reopen($task);

        $row = $this->service()->find($task);
        $this->assertSame('in_progress', $row['status']);
        $this->assertNull($row['done_at']);
        $this->assertSame(1, $this->allocationCount($task));
        $this->assertSame(1, $this->audits('task', 'reopen'));
    }

    public function testReopenOfTaskInProgressIsRejected(): void
    {
        $task = $this->createTask($this->createClient());

        try {
            $this->service()->reopen($task);
            $this->fail('Expected TaskRejected');
        } catch (TaskRejected $e) {
            $this->assertSame('not_done', $e->reason);
        }
    }

    public function testBillingStateFollowsMarkDoneAndReopen(): void
    {
        $task = $this->createTask($this->createClient());
        $aid  = $this->service()->markDone($task, '2026-10', '40.00');
        $this->lockAllocation((int) $aid);
        $this->assertSame('billed', $this->state($task)->billing_state);

        $this->service()->reopen($task);
        $this->assertSame('partial', $this->state($task)->billing_state);
    }
}
