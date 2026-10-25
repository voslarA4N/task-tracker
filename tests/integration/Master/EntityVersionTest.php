<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\EntityService;
use App\Libraries\Master\EntityVersionResolver;
use App\Libraries\Master\VersionRejected;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class EntityVersionTest extends PgTestCase
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

    private function rows(string $table, string $where = 'true'): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM {$table} WHERE {$where}")->getRow()->c;
    }

    private function service(): EntityService
    {
        return new EntityService($this->rw(), new AuditLogger($this->rw()));
    }

    private function resolver(): EntityVersionResolver
    {
        return new EntityVersionResolver($this->rw());
    }

    /**
     * @param array<string, string|null> $over
     *
     * @return array<string, string|null>
     */
    private function changed(int $id, string $at, array $over = ['address_line1' => 'Nova 2']): array
    {
        $row = $this->resolver()->atDate($id, $at);
        $out = [];

        foreach (\App\Libraries\Master\EntityInput::VERSION_FIELDS as $f) {
            $out[$f] = $row[$f] === null ? null : (string) $row[$f];
        }

        return $over + $out;
    }

    public function testNewVersionIsSelectedByDateAndOlderIsUntouched(): void
    {
        $id  = $this->createEntity('SK');
        $vid = $this->service()->newVersion($id, '2026-11-01', $this->changed($id, '2026-10-25'));

        $this->assertGreaterThan(0, $vid);
        $this->assertSame('Hlavna 1', $this->resolver()->atDate($id, '2026-10-31')['address_line1']);
        $this->assertSame('Nova 2', $this->resolver()->atDate($id, '2026-11-01')['address_line1']);
        $this->assertSame('Nova 2', $this->resolver()->atDate($id, '2027-01-01')['address_line1']);
        $this->assertSame(1, $this->rows('entity_versions', "valid_from = '2026-01-01' AND address_line1 = 'Hlavna 1'"));
    }

    public function testInsertBetweenVersions(): void
    {
        $id = $this->createEntity('SK');
        $this->service()->newVersion($id, '2026-11-01', $this->changed($id, '2026-10-25'));
        $this->service()->newVersion($id, '2026-06-01', $this->changed($id, '2026-03-01', ['address_line1' => 'Stredna 5']));

        $this->assertSame('Stredna 5', $this->resolver()->atDate($id, '2026-07-01')['address_line1']);
        $this->assertSame('Hlavna 1', $this->resolver()->atDate($id, '2026-05-31')['address_line1']);
    }

    public function testDuplicateDateRejected(): void
    {
        $id = $this->createEntity('SK');
        $this->service()->newVersion($id, '2026-11-01', $this->changed($id, '2026-10-25'));
        $versions = $this->rows('entity_versions');
        $audit    = $this->rows('audit_log');

        try {
            $this->service()->newVersion($id, '2026-11-01', $this->changed($id, '2026-11-01', ['address_line1' => 'Ina 3']));
            $this->fail('Expected VersionRejected');
        } catch (VersionRejected $e) {
            $this->assertSame('duplicate_date', $e->reason);
        }

        $this->assertSame($versions, $this->rows('entity_versions'));
        $this->assertSame($audit, $this->rows('audit_log'));
    }

    public function testNoChangeRejected(): void
    {
        $id       = $this->createEntity('SK');
        $versions = $this->rows('entity_versions');
        $audit    = $this->rows('audit_log');

        try {
            $this->service()->newVersion($id, '2026-11-01', $this->changed($id, '2026-10-25', []));
            $this->fail('Expected VersionRejected');
        } catch (VersionRejected $e) {
            $this->assertSame('no_change', $e->reason);
        }

        $this->assertSame($versions, $this->rows('entity_versions'));
        $this->assertSame($audit, $this->rows('audit_log'));
    }

    public function testOutOfRangeRejected(): void
    {
        $id       = $this->createEntity('SK');
        $versions = $this->rows('entity_versions');
        $audit    = $this->rows('audit_log');

        foreach (['2025-12-31', '2027-10-27'] as $date) {
            try {
                $this->service()->newVersion($id, $date, $this->changed($id, '2026-10-25'));
                $this->fail('Expected VersionRejected for ' . $date);
            } catch (VersionRejected $e) {
                $this->assertSame('out_of_range', $e->reason);
            }
        }

        $this->assertSame($versions, $this->rows('entity_versions'));
        $this->assertSame($audit, $this->rows('audit_log'));
    }

    public function testAuditHasBeforeAndAfter(): void
    {
        $id  = $this->createEntity('SK');
        $vid = $this->service()->newVersion($id, '2026-11-01', $this->changed($id, '2026-10-25'));

        $row = $this->owner()->query(
            "SELECT before_data->>'address_line1' AS b, after_data->>'address_line1' AS a, after_data->>'valid_from' AS vf
             FROM audit_log WHERE entity_type = 'entity_version' AND action = 'create' AND entity_id = ?",
            [$vid],
        )->getRow();

        $this->assertSame('Hlavna 1', $row->b);
        $this->assertSame('Nova 2', $row->a);
        $this->assertSame('2026-11-01', $row->vf);
    }

    public function testHistory(): void
    {
        $id = $this->createEntity('SK');
        $this->service()->newVersion($id, '2026-11-01', $this->changed($id, '2026-10-25'));

        $history = $this->resolver()->history($id, '2026-10-25');

        $this->assertCount(2, $history);
        $this->assertSame('2026-11-01', $history[0]['valid_from']);
        $this->assertTrue($history[0]['is_future']);
        $this->assertFalse($history[0]['is_current']);
        $this->assertContains('address_line1', $history[0]['changed']);
        $this->assertTrue($history[1]['is_current']);
        $this->assertSame([], $history[1]['changed']);
    }

    public function testNextAfterAndFind(): void
    {
        $id    = $this->createEntity('SK');
        $other = $this->createEntity('PL');
        $vid   = $this->service()->newVersion($id, '2026-11-01', $this->changed($id, '2026-10-25'));

        $this->assertSame('2026-11-01', $this->resolver()->nextAfter($id, '2026-10-26')['valid_from'] ?? null);
        $this->assertNull($this->resolver()->nextAfter($id, '2026-11-01'));
        $this->assertNotNull($this->resolver()->find($id, $vid));
        $this->assertNull($this->resolver()->find($other, $vid));
    }

    public function testClockTodayAtDayBoundary(): void
    {
        Services::injectMock('clock', new FrozenClock('2026-10-31T23:30:00+01:00'));

        $this->assertSame('2026-10-31', service('clock')->today());
    }
}
