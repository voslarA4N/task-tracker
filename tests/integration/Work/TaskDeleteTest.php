<?php

declare(strict_types=1);

namespace Tests\Integration\Work;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Work\TaskHasLockedAllocations;
use App\Libraries\Work\TaskService;
use Config\Services;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class TaskDeleteTest extends PgTestCase
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

    private function count(string $table): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM ' . $table)->getRow()->c;
    }

    public function testDeleteTaskWithoutAllocations(): void
    {
        $task = $this->createTask($this->createClient());

        $this->service()->delete($task);

        $this->assertSame(0, $this->count('tasks'));
        $this->assertSame(1, $this->audits('task', 'delete'));
    }

    public function testDeleteRemovesUnlockedAllocationsWithAudit(): void
    {
        $task = $this->createTask($this->createClient());
        $this->createAllocation($task, '10.00', '2026-10-01');
        $this->createAllocation($task, '5.00', '2026-11-01');

        $this->service()->delete($task);

        $this->assertSame(0, $this->count('tasks'));
        $this->assertSame(0, $this->count('billing_allocations'));
        $this->assertSame(2, $this->audits('billing_allocation', 'delete'));
        $this->assertSame(1, $this->audits('task', 'delete'));
    }

    public function testDeleteWithLockedAllocationRollsBack(): void
    {
        $task   = $this->createTask($this->createClient());
        $locked = $this->createAllocation($task, '10.00', '2026-10-01');
        $this->createAllocation($task, '5.00', '2026-11-01');
        $this->lockAllocation($locked);

        try {
            $this->service()->delete($task);
            $this->fail('Expected TaskHasLockedAllocations');
        } catch (TaskHasLockedAllocations $e) {
            $this->assertSame($task, $e->taskId);
        }

        $this->assertSame(1, $this->count('tasks'));
        $this->assertSame(2, $this->count('billing_allocations'));
        $this->assertSame(0, $this->count('audit_log'));
    }

    public function testDeleteUnknownTask(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->delete(999999);
    }
}
