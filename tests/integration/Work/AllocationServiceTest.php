<?php

declare(strict_types=1);

namespace Tests\Integration\Work;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Work\AllocationLocked;
use App\Libraries\Work\AllocationService;
use Config\Services;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class AllocationServiceTest extends PgTestCase
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

    private function service(): AllocationService
    {
        return new AllocationService($this->rw(), new AuditLogger($this->rw()));
    }

    private function audits(string $action): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM audit_log WHERE entity_type = ? AND action = ?', ['billing_allocation', $action])->getRow()->c;
    }

    public function testAddDefaultsRateFromTaskAndCopiesCurrency(): void
    {
        $task = $this->createTask($this->createClient());
        $id   = $this->service()->add($task, ['hours' => '8.10', 'billing_month' => '2026-10']);
        $row  = $this->service()->find($id);

        $this->assertNotNull($row);
        $this->assertSame('27.00', $row['rate']);
        $this->assertSame('218.70', $row['amount']);
        $this->assertSame('8.10', $row['hours']);
        $this->assertSame('2026-10', $row['billing_month']);
        $this->assertSame('EUR', $row['currency']);
        $this->assertNull($row['invoice_id']);
        $this->assertSame($task, $row['task_id']);
        $this->assertSame(1, $this->audits('create'));
    }

    public function testAddWithRateOverride(): void
    {
        $task = $this->createTask($this->createClient());
        $id   = $this->service()->add($task, ['hours' => '8.10', 'billing_month' => '2026-10', 'rate' => '30.00']);

        $this->assertSame('243.00', $this->service()->find($id)['amount']);
    }

    public function testAddCopiesClientCurrency(): void
    {
        $task = $this->createTask($this->createClient(['country' => 'PL', 'currency' => 'PLN']));
        $id   = $this->service()->add($task, ['hours' => '1.00', 'billing_month' => '2026-10']);

        $this->assertSame('PLN', $this->service()->find($id)['currency']);
    }

    public function testAddToDoneAndUnknownTask(): void
    {
        $task = $this->createTask($this->createClient(), ['status' => 'in_progress']);
        $this->assertGreaterThan(0, $this->service()->add($task, ['hours' => '1.00', 'billing_month' => '2026-10']));

        $this->expectException(InvalidArgumentException::class);
        $this->service()->add(999999, ['hours' => '1.00', 'billing_month' => '2026-10']);
    }

    public function testUpdateChangesUnlockedAllocation(): void
    {
        $task = $this->createTask($this->createClient());
        $id   = $this->createAllocation($task, '8.10', '2026-10');

        $this->assertTrue($this->service()->update($id, ['hours' => '10.00', 'billing_month' => '2026-11', 'rate' => '27.00']));

        $row = $this->service()->find($id);
        $this->assertSame('10.00', $row['hours']);
        $this->assertSame('2026-11', $row['billing_month']);
        $this->assertSame('270.00', $row['amount']);
        $this->assertSame(1, $this->audits('update'));

        $after = json_decode((string) $this->owner()->query("SELECT after_data FROM audit_log WHERE action = 'update' AND entity_type = 'billing_allocation'")->getRow()->after_data, true);
        $this->assertArrayNotHasKey('rate', $after);
        $this->assertArrayHasKey('hours', $after);
    }

    public function testUpdateWithoutChangeReturnsFalse(): void
    {
        $task = $this->createTask($this->createClient());
        $id   = $this->createAllocation($task, '8.10', '2026-10');

        $this->assertFalse($this->service()->update($id, ['hours' => '8.10', 'billing_month' => '2026-10', 'rate' => '27.00']));
        $this->assertSame(0, $this->audits('update'));
    }

    public function testUpdateLockedThrows(): void
    {
        $task = $this->createTask($this->createClient());
        $id   = $this->createAllocation($task, '8.10', '2026-10');
        $this->lockAllocation($id);

        try {
            $this->service()->update($id, ['hours' => '10.00', 'billing_month' => '2026-11', 'rate' => '27.00']);
            $this->fail('AllocationLocked expected');
        } catch (AllocationLocked $e) {
            $this->assertSame($id, $e->allocationId);
        }

        $row = $this->service()->find($id);
        $this->assertSame('8.10', $row['hours']);
        $this->assertSame('2026-10', $row['billing_month']);
    }

    public function testDeleteUnlocked(): void
    {
        $task = $this->createTask($this->createClient());
        $id   = $this->createAllocation($task, '8.10', '2026-10');

        $this->service()->delete($id);

        $this->assertNull($this->service()->find($id));
        $this->assertSame(1, $this->audits('delete'));
    }

    public function testDeleteLockedThrows(): void
    {
        $task = $this->createTask($this->createClient());
        $id   = $this->createAllocation($task, '8.10', '2026-10');
        $this->lockAllocation($id);

        try {
            $this->service()->delete($id);
            $this->fail('AllocationLocked expected');
        } catch (AllocationLocked) {
            $this->assertNotNull($this->service()->find($id));
        }
    }

    public function testTaskRateChangeDoesNotAffectExistingAllocation(): void
    {
        $task = $this->createTask($this->createClient());
        $id   = $this->service()->add($task, ['hours' => '8.10', 'billing_month' => '2026-10']);
        $this->owner()->query("UPDATE tasks SET rate = '99.00' WHERE id = ?", [$task]);

        $this->assertSame('218.70', $this->service()->find($id)['amount']);
    }

    public function testUnknownIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->update(999999, ['hours' => '1.00', 'billing_month' => '2026-10', 'rate' => '1.00']);
    }

    public function testListForTaskOrdered(): void
    {
        $task = $this->createTask($this->createClient());
        $this->createAllocation($task, '1.00', '2026-12');
        $this->createAllocation($task, '2.00', '2026-10');

        $list = $this->service()->listForTask($task);
        $this->assertSame(['2026-10', '2026-12'], array_column($list, 'billing_month'));
    }
}
