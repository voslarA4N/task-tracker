<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Master\EntityDirectory;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class EntityDirectoryTest extends PgTestCase
{
    use MasterFixtures;

    private const TODAY = '2026-10-25';

    /**
     * @var array<string, int>
     */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();

        $this->ids['A'] = $this->createEntity('SK', ['name_internal' => 'A', 'active_from' => '2026-01-01']);
        $this->ids['B'] = $this->createEntity('SK', ['name_internal' => 'B', 'active_from' => '2025-01-01', 'active_to' => '2025-12-31']);
        $this->ids['C'] = $this->createEntity('PL', ['name_internal' => 'C', 'active_from' => '2026-12-01']);
        $this->ids['D'] = $this->createEntity('PL', ['name_internal' => 'D', 'active_from' => '2026-10-01']);
        $this->ids['E'] = $this->createEntity('SK', ['name_internal' => 'E', 'active_from' => '2026-01-01', 'active_to' => '2026-10-25']);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function directory(): EntityDirectory
    {
        return new EntityDirectory($this->rw());
    }

    /**
     * @param list<array{id: int, name_internal: string}> $rows
     *
     * @return list<string>
     */
    private function names(array $rows): array
    {
        return array_map(static fn (array $r): string => $r['name_internal'], $rows);
    }

    public function testForSelectReturnsAllEntitiesIncludingEnded(): void
    {
        $rows = $this->directory()->forSelect(self::TODAY);

        $this->assertCount(5, $rows);
        $byName = [];

        foreach ($rows as $row) {
            $byName[$row['name_internal']] = $row;
        }

        $this->assertTrue($byName['B']['ended']);
        $this->assertFalse($byName['E']['ended'], 'active_to = dnes este nie je ukonceny');
        $this->assertFalse($byName['A']['ended']);
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->names($rows));
    }

    public function testActiveAtTodayIncludesOverlapNewestFirst(): void
    {
        $rows = $this->directory()->activeAt(self::TODAY);

        // D ma najnovsie active_from, A a E maju zhodne, rozhoduje id DESC.
        $this->assertSame(['D', 'E', 'A'], $this->names($rows));
        $this->assertSame($this->ids['D'], $rows[0]['id']);
    }

    public function testActiveAtPastReturnsOnlyEndedEntity(): void
    {
        $this->assertSame(['B'], $this->names($this->directory()->activeAt('2025-06-01')));
    }

    public function testActiveAtFutureContainsPreparedAndExcludesEndedBoundary(): void
    {
        $names = $this->names($this->directory()->activeAt('2026-12-01'));

        $this->assertContains('C', $names);
        $this->assertContains('D', $names);
        $this->assertContains('A', $names);
        $this->assertNotContains('E', $names);
    }

    public function testListAllStatusesAndOrder(): void
    {
        $rows = $this->directory()->listAll(self::TODAY);

        $status = [];

        foreach ($rows as $row) {
            $status[$row['name_internal']] = $row['status'];
        }

        $this->assertSame('active', $status['A']);
        $this->assertSame('active', $status['D']);
        $this->assertSame('active', $status['E']);
        $this->assertSame('prepared', $status['C']);
        $this->assertSame('ended', $status['B']);

        $this->assertSame(['D', 'E', 'A', 'C', 'B'], $this->names($rows));

        $c = array_values(array_filter($rows, static fn (array $r): bool => $r['name_internal'] === 'C'))[0];
        $this->assertSame('pl_exempt_113_1', $c['vat_regime']);
        $this->assertSame('PL', $c['country']);
        $this->assertSame('PLN', $c['currency']);
    }
}
