<?php

declare(strict_types=1);

namespace Tests\Integration\Work;

use App\Libraries\Platform\FrozenClock;
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
final class TaskQueriesDashboardTest extends PgTestCase
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

    private function seedEur(): int
    {
        $c = $this->createClient();
        $t = $this->createTask($c, ['title' => 'E'] + $this->done());
        $this->createAllocation($t, '8.00', '2026-10');
        $this->createAllocation($t, '1.00', '2026-10');
        $this->createAllocation($t, '5.00', '2026-11');
        $this->lockAllocation($this->createAllocation($t, '3.00', '2026-10'));

        return $c;
    }

    public function testDueSumsPerCurrency(): void
    {
        $eur = $this->seedEur();
        $d   = $this->queries()->dashboard('2026-10', null);
        $this->assertCount(1, $d['due']);
        $this->assertSame('EUR', trim($d['due'][0]['currency']));
        $this->assertSame('243.00', $d['due'][0]['amount']);
        $this->assertSame('9.00', $d['due'][0]['hours']);

        $pln = $this->createClient(['name' => 'PL', 'currency' => 'PLN', 'country' => 'PL', 'identifier_value' => '5260250274', 'billing_mode' => 'domestic']);
        $pt  = $this->createTask($pln, ['rate' => '100.00']);
        $this->createAllocation($pt, '2.00', '2026-10');
        $d = $this->queries()->dashboard('2026-10', null);
        $this->assertSame(['EUR', 'PLN'], array_map(static fn (array $r): string => trim($r['currency']), $d['due']));
        $this->assertSame('200.00', $d['due'][1]['amount']);

        $d = $this->queries()->dashboard('2026-10', $eur);
        $this->assertCount(1, $d['due']);
    }

    public function testWipCountsOnlyInProgress(): void
    {
        $c = $this->createClient();
        $this->createTask($c, ['hours' => '1.10', 'rate' => '27.50']);
        $this->createTask($c, ['hours' => '1.10', 'rate' => '27.50']);
        $this->createTask($c, ['hours' => '5.00'] + $this->done());

        $d = $this->queries()->dashboard('2026-10', null);
        $this->assertCount(1, $d['wip']);
        // round(1.10 * 27.50, 2) = 30.25, dvakrat
        $this->assertSame('60.50', $d['wip'][0]['amount']);
        $this->assertSame('2.20', $d['wip'][0]['hours']);
    }

    public function testGroups(): void
    {
        $this->seedEur();
        $c = $this->createClient(['name' => 'Many']);

        for ($i = 0; $i < 12; $i++) {
            $this->createTask($c, ['title' => 'W' . $i]);
        }

        $p = $this->createTask($c, ['title' => 'Part']);
        $this->lockAllocation($this->createAllocation($p, '8.00', '2026-09'));

        $g = $this->queries()->dashboard('2026-10', null)['groups'];
        $this->assertSame(1, $g['done_due']['total']);
        $this->assertCount(1, $g['done_due']['rows']);
        $this->assertSame(2, $g['partial']['total']);
        $this->assertSame(13, $g['in_progress']['total']);
        $this->assertCount(10, $g['in_progress']['rows']);
    }

    public function testConstantQueryCount(): void
    {
        $c = $this->createClient();

        for ($i = 0; $i < 30; $i++) {
            $t = $this->createTask($c);
            $this->createAllocation($t, '1.00', '2026-10');
        }

        $count    = 0;
        $listener = static function () use (&$count): void {
            $count++;
        };
        Events::on('DBQuery', $listener);
        $this->queries()->dashboard('2026-10', null);
        Events::removeListener('DBQuery', $listener);

        $this->assertLessThanOrEqual(5, $count);
    }
}
