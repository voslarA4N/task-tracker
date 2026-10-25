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
final class TaskBillingViewTest extends PgTestCase
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


    private function state(int $task): object
    {
        return $this->owner()->query('SELECT * FROM task_billing_v WHERE task_id = ?', [$task])->getRow();
    }

    private function done(): array
    {
        return ['status' => 'done', 'done_at' => '2026-10-25 10:00:00+02'];
    }

    public function testNoAllocationsIsUnbilled(): void
    {
        $task = $this->createTask($this->createClient());
        $row  = $this->state($task);
        $this->assertSame('unbilled', $row->billing_state);
        $this->assertSame('0.00', $row->allocated_hours);
    }

    public function testInProgressMixedIsPartialWithSums(): void
    {
        $task = $this->createTask($this->createClient());
        $a    = $this->createAllocation($task, '8.00', '2026-10');
        $this->createAllocation($task, '4.50', '2026-11');
        $this->lockAllocation($a);

        $row = $this->state($task);
        $this->assertSame('partial', $row->billing_state);
        $this->assertSame('8.00', $row->billed_hours);
        $this->assertSame('4.50', $row->planned_hours);
        $this->assertSame('12.50', $row->allocated_hours);
        $this->assertSame('216.00', $row->billed_amount);
        $this->assertSame('121.50', $row->planned_amount);
    }

    public function testDoneAllLockedIsBilled(): void
    {
        $task = $this->createTask($this->createClient(), $this->done());
        $this->lockAllocation($this->createAllocation($task, '8.00', '2026-10'));
        $this->lockAllocation($this->createAllocation($task, '2.00', '2026-11'));
        $this->assertSame('billed', $this->state($task)->billing_state);
    }

    public function testDoneMixedIsPartial(): void
    {
        $task = $this->createTask($this->createClient(), $this->done());
        $this->lockAllocation($this->createAllocation($task, '8.00', '2026-10'));
        $this->createAllocation($task, '2.00', '2026-11');
        $this->assertSame('partial', $this->state($task)->billing_state);
    }

    public function testDoneWithoutLockedIsUnbilled(): void
    {
        $task = $this->createTask($this->createClient(), $this->done());
        $this->createAllocation($task, '8.00', '2026-10');
        $this->assertSame('unbilled', $this->state($task)->billing_state);
    }

    public function testInProgressAllLockedIsPartial(): void
    {
        $task = $this->createTask($this->createClient());
        $this->lockAllocation($this->createAllocation($task, '8.00', '2026-10'));
        $this->assertSame('partial', $this->state($task)->billing_state);
    }

    public function testRwCanReadButNotUpdateView(): void
    {
        $this->createTask($this->createClient());
        $this->assertCount(1, $this->rw()->query('SELECT * FROM task_billing_v')->getResult());
        $this->assertDbError('/permission denied|cannot update|cannot change|not automatically updatable/i', fn () => $this->rw()->query("UPDATE task_billing_v SET status = 'done'"));
    }
}
