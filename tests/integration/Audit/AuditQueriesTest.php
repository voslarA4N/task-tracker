<?php

declare(strict_types=1);

namespace Tests\Integration\Audit;

use App\Libraries\Audit\AuditFilter;
use App\Libraries\Audit\AuditQueries;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class AuditQueriesTest extends PgTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        parent::tearDown();
    }

    private function q(): AuditQueries
    {
        return new AuditQueries($this->rw());
    }

    private function put(string $at, string $type, string $action, ?string $before = null, ?string $after = null): int
    {
        $this->owner()->query(
            'INSERT INTO audit_log (occurred_at, user_id, entity_type, entity_id, action, before_data, after_data, ip) VALUES (?, NULL, ?, 1, ?, ?::jsonb, ?::jsonb, ?)',
            [$at, $type, $action, $before, $after, '127.0.0.1'],
        );

        return (int) $this->owner()->insertID();
    }

    /**
     * @param array<string, string> $get
     */
    private function f(array $get = []): AuditFilter
    {
        return AuditFilter::fromQuery($get, $this->q()->entityTypes(), $this->q()->actions());
    }

    public function testNewestFirstAndFilters(): void
    {
        $this->put('2026-11-01T10:00:00+01:00', 'task', 'create');
        $this->put('2026-11-02T10:00:00+01:00', 'invoice', 'issue');
        $this->put('2026-11-03T10:00:00+01:00', 'invoice', 'update');

        $all = $this->q()->list($this->f())['rows'];
        $this->assertSame(['update', 'issue', 'create'], array_column($all, 'action'));

        $inv = $this->q()->list($this->f(['entity' => 'invoice']))['rows'];
        $this->assertCount(2, $inv);

        $upd = $this->q()->list($this->f(['action' => 'update']))['rows'];
        $this->assertCount(1, $upd);

        $this->assertSame(['invoice', 'task'], $this->q()->entityTypes());
        $this->assertSame(['create', 'issue', 'update'], $this->q()->actions());
        $this->assertNull($all[0]['user_label']);
    }

    public function testUnknownEntityIsIgnored(): void
    {
        $this->put('2026-11-01T10:00:00+01:00', 'task', 'create');
        $this->assertNull($this->f(['entity' => "x'; DROP"])->entityType);
    }

    public function testDateRangeIncludesWholeDay(): void
    {
        $this->put('2026-11-03T23:30:00+01:00', 'task', 'create');

        $this->assertCount(1, $this->q()->list($this->f(['to' => '2026-11-03']))['rows']);
        $this->assertCount(0, $this->q()->list($this->f(['to' => '2026-11-02']))['rows']);
        $this->assertCount(1, $this->q()->list($this->f(['from' => '2026-11-03']))['rows']);
        $this->assertCount(0, $this->q()->list($this->f(['from' => '2026-11-04']))['rows']);
    }

    public function testPaging(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->put('2026-11-01T10:00:00+01:00', 'task', 'create');
        }

        $p1 = $this->q()->list($this->f());
        $this->assertCount(50, $p1['rows']);
        $this->assertTrue($p1['hasMore']);

        $p2 = $this->q()->list($this->f(['page' => '2']));
        $this->assertCount(1, $p2['rows']);
        $this->assertFalse($p2['hasMore']);
    }

    public function testFind(): void
    {
        $id = $this->put('2026-11-01T10:00:00+01:00', 'task', 'update', '{"a":"1"}', '{"a":"2"}');

        $row = $this->q()->find($id);
        $this->assertNotNull($row);
        $this->assertSame(['a' => '1'], $row['before_data']);
        $this->assertSame(['a' => '2'], $row['after_data']);
        $this->assertNull($this->q()->find($id + 999));
    }
}
