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
final class AllocationSchemaTest extends PgTestCase
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

    private function insertAllocation(int $taskId, string $hours, string $month, string $amount, string $currency = 'EUR'): void
    {
        $this->owner()->query(
            'INSERT INTO billing_allocations (task_id, hours, billing_month, rate, amount, currency, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, now(), now())',
            [$taskId, $hours, $month, '27.00', $amount, $currency],
        );
    }

    public function testZeroHoursRejected(): void
    {
        $task = $this->createTask($this->createClient());
        $this->assertDbError('/violates check constraint/', fn () => $this->insertAllocation($task, '0.00', '2026-10', '0.00'));
    }

    public function testBadMonthRejected(): void
    {
        $task = $this->createTask($this->createClient());
        $this->assertDbError('/violates check constraint/', fn () => $this->insertAllocation($task, '1.00', '2026-13', '27.00'));
        $this->assertDbError('/violates check constraint/', fn () => $this->insertAllocation($task, '1.00', '2026-1', '27.00'));
    }

    public function testAmountMustMatchHoursTimesRate(): void
    {
        $task = $this->createTask($this->createClient());
        $this->assertDbError('/violates check constraint/', fn () => $this->insertAllocation($task, '8.10', '2026-10', '218.71'));
        $this->insertAllocation($task, '8.10', '2026-10', '218.70');
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations')->getRow()->c);
    }

    public function testUnknownCurrencyRejected(): void
    {
        $task = $this->createTask($this->createClient());
        $this->assertDbError('/violates foreign key constraint/', fn () => $this->insertAllocation($task, '1.00', '2026-10', '27.00', 'USD'));
    }

    public function testDoneTaskRequiresDoneAt(): void
    {
        $client = $this->createClient();
        $this->assertDbError('/violates check constraint/', fn () => $this->createTask($client, ['status' => 'done', 'done_at' => null]));
        $this->assertDbError('/violates check constraint/', fn () => $this->createTask($client, ['status' => 'in_progress', 'done_at' => '2026-10-25 10:00:00+02']));
    }

    public function testImportKeyIsUnique(): void
    {
        $client = $this->createClient();
        $key    = str_repeat('a', 64);
        $this->createTask($client, ['import_key' => $key]);
        $this->assertDbError('/duplicate key/', fn () => $this->createTask($client, ['import_key' => $key]));
    }

    public function testNumericReturnedAsString(): void
    {
        $task = $this->createTask($this->createClient());
        $this->createAllocation($task, '8.10', '2026-10');
        $row = $this->owner()->query('SELECT hours, amount FROM billing_allocations')->getRow();
        $this->assertIsString($row->hours);
        $this->assertSame('218.70', $row->amount);
    }
}
