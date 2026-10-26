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
final class TaskQueriesListTest extends PgTestCase
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

    /**
     * @return list<int>
     */
    private function ids(TaskFilter $f): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->queries()->list($f)['rows']);
    }

    /**
     * @param array<string, string> $q
     */
    private function filter(array $q): TaskFilter
    {
        return TaskFilter::fromQuery($q);
    }

    /**
     * @return array<string, int>
     */
    private function seed(): array
    {
        $c = $this->createClient();
        $a = $this->createTask($c, ['title' => 'Listobox A']);
        $b = $this->createTask($c, ['title' => 'B'] + $this->done());
        $this->createAllocation($b, '8.00', '2026-11');
        $billed = $this->createTask($c, ['title' => 'C'] + $this->done());
        $this->lockAllocation($this->createAllocation($billed, '40.00', '2026-10'));
        $d = $this->createTask($c, ['title' => 'D'] + $this->done());
        $p = $this->createTask($c, ['title' => 'P']);
        $this->lockAllocation($this->createAllocation($p, '8.00', '2026-10'));

        return ['client' => $c, 'a' => $a, 'b' => $b, 'billed' => $billed, 'd' => $d, 'p' => $p];
    }

    public function testStateFilters(): void
    {
        $s      = $this->seed();
        $sorted = static function (array $x): array {
            sort($x);

            return $x;
        };
        $this->assertSame($sorted([$s['a'], $s['b'], $s['p']]), $sorted($this->ids($this->filter([]))));
        $this->assertSame($sorted([$s['a'], $s['p']]), $sorted($this->ids($this->filter(['state' => 'in_progress']))));
        $this->assertSame($sorted([$s['b'], $s['d']]), $sorted($this->ids($this->filter(['state' => 'done']))));
        $this->assertSame([$s['p']], $this->ids($this->filter(['state' => 'partial'])));
        $this->assertSame([$s['billed']], $this->ids($this->filter(['state' => 'billed'])));
        $this->assertCount(5, $this->ids($this->filter(['state' => 'all'])));
    }

    public function testClientAndMonthFilter(): void
    {
        $s     = $this->seed();
        $other = $this->createClient(['name' => 'Other']);
        $this->createTask($other, ['title' => 'Cudzia']);
        $this->assertCount(5, $this->ids($this->filter(['client' => (string) $s['client'], 'state' => 'all'])));
        $this->assertSame([$s['b']], $this->ids($this->filter(['month' => '2026-11', 'state' => 'all'])));
    }

    public function testGroupDrillDownMatchesDashboard(): void
    {
        // WR-02: zoznam z dlazdice/skupiny dashboardu ma presne tie iste ulohy ako dashboard.
        $c = $this->createClient();
        $a = $this->createTask($c, ['title' => 'A'] + $this->done());
        $this->createAllocation($a, '2.00', '2026-10');
        $b = $this->createTask($c, ['title' => 'B'] + $this->done());
        $this->lockAllocation($this->createAllocation($b, '1.00', '2026-09'));
        $this->createAllocation($b, '1.00', '2026-10');
        $p = $this->createTask($c, ['title' => 'P']);
        $this->lockAllocation($this->createAllocation($p, '1.00', '2026-09'));
        $w = $this->createTask($c, ['title' => 'W']);
        $this->createAllocation($w, '1.00', '2026-10');
        $x = $this->createTask($c, ['title' => 'X'] + $this->done());
        $this->createAllocation($x, '1.00', '2026-11');

        $sorted = static function (array $ids): array {
            sort($ids);

            return $ids;
        };
        $groups = $this->queries()->dashboard('2026-10', null)['groups'];

        foreach (['done_due', 'partial', 'in_progress'] as $group) {
            $expected = array_map(static fn (array $r): int => (int) $r['id'], $groups[$group]['rows']);
            $listed   = $this->ids($this->filter(['group' => $group, 'month' => '2026-10']));
            $this->assertSame($sorted($expected), $sorted($listed), $group);
            $this->assertCount($groups[$group]['total'], $listed, $group);
        }

        // Dlazdica Na fakturaciu: kazda uloha s nezamknutou castou v mesiaci, aj nezapracovana (ALC-05).
        $this->assertSame($sorted([$a, $b, $w]), $sorted($this->ids($this->filter(['group' => 'due', 'month' => '2026-10']))));
        $this->assertSame([$x], $this->ids($this->filter(['group' => 'due', 'month' => '2026-11'])));
        $this->assertSame([$w], $this->ids($this->filter(['group' => 'due', 'month' => '2026-10', 'client' => (string) $c, 'q' => 'W'])));
    }

    public function testTextSearchCaseInsensitiveOverTitleUrlNote(): void
    {
        $c = $this->createClient();
        $a = $this->createTask($c, ['title' => 'Listobox A']);
        $b = $this->createTask($c, ['title' => 'x', 'url' => 'https://t.example/LISTOBOX/1']);
        $n = $this->createTask($c, ['title' => 'y', 'note' => 'poznamka listobox']);
        $this->createTask($c, ['title' => 'nic']);
        $ids = $this->ids($this->filter(['q' => 'listobox']));
        sort($ids);
        $this->assertSame([$a, $b, $n], $ids);
    }

    public function testWildcardsAreEscaped(): void
    {
        $c = $this->createClient();
        $this->createTask($c, ['title' => 'obycajna']);
        $pct = $this->createTask($c, ['title' => '50% zľava']);
        $us  = $this->createTask($c, ['title' => 'a_b']);
        $this->createTask($c, ['title' => 'axb']);
        $this->assertSame([$pct], $this->ids($this->filter(['q' => '%'])));
        $this->assertSame([$us], $this->ids($this->filter(['q' => '_'])));
    }

    public function testSingleQueryForManyTasks(): void
    {
        $c = $this->createClient();

        for ($i = 0; $i < 20; $i++) {
            $t = $this->createTask($c, ['title' => 'T' . $i]);
            $this->createAllocation($t, '1.00', '2026-10');
            $this->createAllocation($t, '1.00', '2026-11');
        }

        $count    = 0;
        $listener = static function () use (&$count): void {
            $count++;
        };
        Events::on('DBQuery', $listener);
        $result = $this->queries()->list(TaskFilter::fromQuery([]));
        Events::removeListener('DBQuery', $listener);

        $this->assertSame(1, $count);
        $this->assertCount(20, $result['rows']);
    }

    public function testLimitTruncatesAndValuesAreStrings(): void
    {
        $c = $this->createClient();

        for ($i = 0; $i < 5; $i++) {
            $this->createTask($c, ['title' => 'T' . $i]);
        }

        $result = $this->queries()->list(TaskFilter::fromQuery([]), 3);
        $this->assertCount(3, $result['rows']);
        $this->assertTrue($result['truncated']);
        $row = $result['rows'][0];
        $this->assertSame('40.00', $row['hours']);
        $this->assertSame('1080.00', $row['quoted_amount']);
        $this->assertSame('unbilled', $row['billing_state']);
        $this->assertSame('40.00', $row['remaining']);
    }
}
