<?php

declare(strict_types=1);

namespace Tests\Integration\Work;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class AllocationGuardTest extends PgTestCase
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

    public function testUnlockedAllocationCanBeUpdatedByRw(): void
    {
        $task  = $this->createTask($this->createClient());
        $alloc = $this->createAllocation($task, '8.00', '2026-10');
        $this->rw()->query('UPDATE billing_allocations SET hours = 10.00, amount = 270.00, updated_at = now() WHERE id = ?', [$alloc]);
        $this->assertSame('10.00', $this->owner()->query('SELECT hours FROM billing_allocations WHERE id = ?', [$alloc])->getRow()->hours);
    }

    public function testLockedAllocationRejectsUpdateAndDeleteForRw(): void
    {
        $task  = $this->createTask($this->createClient());
        $alloc = $this->createAllocation($task, '8.00', '2026-10');
        $this->lockAllocation($alloc);

        $this->assertDbError('/is locked/', fn () => $this->rw()->query('UPDATE billing_allocations SET hours = 9.00, amount = 243.00 WHERE id = ?', [$alloc]));
        $this->assertDbError('/is locked/', fn () => $this->rw()->query('DELETE FROM billing_allocations WHERE id = ?', [$alloc]));
    }

    public function testLockedAllocationRejectsUpdateForOwner(): void
    {
        $task  = $this->createTask($this->createClient());
        $alloc = $this->createAllocation($task, '8.00', '2026-10');
        $this->lockAllocation($alloc);

        $this->assertDbError('/is locked/', fn () => $this->owner()->query('UPDATE billing_allocations SET hours = 9.00, amount = 243.00 WHERE id = ?', [$alloc]));
    }

    public function testOwnerCanLockUnlockedAllocation(): void
    {
        $task  = $this->createTask($this->createClient());
        $alloc = $this->createAllocation($task, '8.00', '2026-10');
        $invoice = $this->lockAllocation($alloc);
        $this->assertSame((string) $invoice, (string) $this->owner()->query('SELECT invoice_id FROM billing_allocations WHERE id = ?', [$alloc])->getRow()->invoice_id);
    }

    public function testRwCannotTouchProtectedAllocationColumns(): void
    {
        $task = $this->createTask($this->createClient());
        $this->createAllocation($task, '8.00', '2026-10');

        $this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE billing_allocations SET invoice_id = 1'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE billing_allocations SET task_id = ' . $task));
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE billing_allocations SET currency = 'PLN'"));
    }

    public function testRwCannotTouchProtectedTaskColumns(): void
    {
        $this->createTask($this->createClient());

        $this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE tasks SET created_at = now()'));
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE tasks SET import_key = '" . str_repeat('b', 64) . "'"));
    }

    public function testTaskWithAllocationCannotBeDeleted(): void
    {
        $task = $this->createTask($this->createClient());
        $this->createAllocation($task, '8.00', '2026-10');

        $this->assertDbError('/violates (RESTRICT setting of )?foreign key/', fn () => $this->rw()->query('DELETE FROM tasks WHERE id = ?', [$task]));
    }

    public function testTaskWithoutAllocationCanBeDeleted(): void
    {
        $task = $this->createTask($this->createClient());
        $this->rw()->query('DELETE FROM tasks WHERE id = ?', [$task]);
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM tasks')->getRow()->c);
    }
}
