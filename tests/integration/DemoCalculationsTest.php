<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Money;
use App\Models\DemoCalculationModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class DemoCalculationsTest extends PgTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->owner()->query('TRUNCATE demo_calculations RESTART IDENTITY');
    }

    private function model(): DemoCalculationModel
    {
        return new DemoCalculationModel($this->rw());
    }

    private function now(): \DateTimeImmutable
    {
        return (new FrozenClock('2026-10-23T10:00:00+02:00'))->now();
    }

    public function testRecordAndLatestReturnNumericAsString(): void
    {
        $id = $this->model()->record('1.25', '27.00', Money::of('27.00')->timesHours('1.25'), $this->now());
        $this->assertGreaterThan(0, $id);

        $rows = $this->model()->latest();
        $this->assertCount(1, $rows);
        $this->assertIsString($rows[0]['amount']);
        $this->assertSame('33.75', $rows[0]['amount']);
        $this->assertSame('1.25', $rows[0]['hours']);
    }

    public function testTotalMatchesSqlSum(): void
    {
        mt_srand(20261024);

        for ($i = 0; $i < 50; $i++) {
            $hours = sprintf('%d.%02d', mt_rand(1, 999), mt_rand(0, 99));
            $rate  = sprintf('%d.%02d', mt_rand(0, 999), mt_rand(0, 99));
            $this->model()->record($hours, $rate, Money::of($rate)->timesHours($hours), $this->now());
        }

        $this->assertSame($this->model()->sqlTotal(), $this->model()->totalAmount()->toDecimalString());
    }

    public function testEmptyTotalIsZero(): void
    {
        $this->assertSame('0.00', $this->model()->totalAmount()->toDecimalString());
        $this->assertSame('0.00', $this->model()->sqlTotal());
    }

    public function testInsertOnlyForRw(): void
    {
        $this->model()->record('1.00', '1.00', Money::of('1.00')->timesHours('1.00'), $this->now());

        $this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE demo_calculations SET amount = 0'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM demo_calculations'));
    }

    public function testAmountCheckConstraint(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/demo_calculations_amount_check/');
        $this->owner()->query("INSERT INTO demo_calculations (hours, rate, amount, created_at) VALUES ('1.25', '27.00', '33.76', now())");
    }

    public function testTablesAreOwnedByOwnerRole(): void
    {
        foreach (['demo_calculations', 'migrations'] as $table) {
            $row = $this->owner()->query('SELECT tableowner FROM pg_tables WHERE tablename = ?', [$table])->getRow();
            $this->assertSame('app_owner', $row->tableowner, $table);
        }
    }

    public function testCreatedAtComesFromClock(): void
    {
        $this->model()->record('1.00', '2.00', Money::of('2.00')->timesHours('1.00'), $this->now());
        $row = $this->owner()->query("SELECT to_char(created_at AT TIME ZONE 'Europe/Bratislava', 'YYYY-MM-DD HH24:MI:SS') AS c FROM demo_calculations")->getRow();
        $this->assertStringStartsWith('2026-10-23 10:00:00', $row->c);
    }
}
