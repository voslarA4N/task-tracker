<?php

declare(strict_types=1);

namespace Tests\Integration\Work;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Work\TaskFilter;
use App\Libraries\Work\TaskQueries;
use CodeIgniter\Events\Events;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class TaskQueriesDetailTest extends PgTestCase
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

    private function queries(): TaskQueries
    {
        return new TaskQueries($this->rw());
    }

    /**
     * @return array<string, string>
     */
    private function done(): array
    {
        return ['status' => 'done', 'done_at' => '2026-10-25 10:00:00+02'];
    }

    public function testPartialTask(): void
    {
        $t = $this->createTask($this->createClient());
        $this->lockAllocation($this->createAllocation($t, '16.00', '2026-10'));
        $this->createAllocation($t, '8.00', '2026-11');

        $d = $this->queries()->detail($t);
        $this->assertNotNull($d);
        $this->assertSame('16.00', $d['billed_hours']);
        $this->assertSame('8.00', $d['planned_hours']);
        $this->assertSame('16.00', $d['remaining']);
        $this->assertSame('-16.00', $d['delta']);
        $this->assertSame('partial', $d['billing_state']);
        $this->assertTrue($d['has_locked']);
        $this->assertFalse($d['over_quoted']);
        $this->assertSame(40, $d['billed_pct']);
        $this->assertSame(20, $d['planned_pct']);
        $this->assertSame('1080.00', $d['quoted_amount']);
        $this->assertSame('EUR', $d['currency']);
        $this->assertCount(2, $d['allocations']);
    }

    public function testOverQuoted(): void
    {
        $t = $this->createTask($this->createClient());
        $this->createAllocation($t, '26.00', '2026-11');
        $this->createAllocation($t, '20.00', '2026-10');

        $d = $this->queries()->detail($t);
        $this->assertSame('0.00', $d['remaining']);
        $this->assertSame('6.00', $d['delta']);
        $this->assertTrue($d['over_quoted']);
        $this->assertSame(['2026-10', '2026-11'], array_column($d['allocations'], 'billing_month'));
    }

    public function testMissingReturnsNull(): void
    {
        $this->assertNull($this->queries()->detail(999999));
    }
}
