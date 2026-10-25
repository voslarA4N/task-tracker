<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\ClientService;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class ClientArchiveTest extends PgTestCase
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

    private function service(): ClientService
    {
        return new ClientService($this->rw(), new AuditLogger($this->rw()));
    }

    private function audits(string $action): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM audit_log WHERE entity_type = ? AND action = ?', ['client', $action])->getRow()->c;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    public function testArchiveSetsTimestampAndAudits(): void
    {
        $id     = $this->createClient();
        $before = $this->service()->find($id);

        $this->assertTrue($this->service()->archive($id));

        $row = $this->owner()->query('SELECT archived_at, extract(epoch FROM archived_at) AS e FROM clients WHERE id = ?', [$id])->getRow();
        $this->assertSame((string) service('clock')->now()->getTimestamp(), (string) (int) $row->e);

        $audit = $this->owner()->query("SELECT before_data->>'archived_at' AS b, after_data->>'archived_at' AS a FROM audit_log WHERE entity_type = 'client' AND action = 'archive'")->getRow();
        $this->assertNull($audit->b);
        $this->assertNotNull($audit->a);

        $after = $this->service()->find($id);
        $this->assertNotNull($before);
        $this->assertNotNull($after);
        unset($before['archived_at'], $before['updated_at'], $after['archived_at'], $after['updated_at']);
        $this->assertSame($before, $after);
    }

    public function testSecondArchiveIsNoop(): void
    {
        $id = $this->createClient();
        $this->assertTrue($this->service()->archive($id));
        $this->assertFalse($this->service()->archive($id));
        $this->assertSame(1, $this->audits('archive'));
    }

    public function testListFilter(): void
    {
        $id = $this->createClient();
        $this->service()->archive($id);

        $this->assertNotContains($id, $this->ids($this->service()->listAll(false)));
        $this->assertContains($id, $this->ids($this->service()->listAll(true)));
    }

    public function testUnarchive(): void
    {
        $id = $this->createClient();
        $this->assertFalse($this->service()->unarchive($id));
        $this->service()->archive($id);

        $this->assertTrue($this->service()->unarchive($id));
        $this->assertFalse($this->service()->unarchive($id));
        $this->assertSame(1, $this->audits('unarchive'));
        $this->assertContains($id, $this->ids($this->service()->listAll(false)));
        $this->assertNull($this->owner()->query('SELECT archived_at FROM clients WHERE id = ?', [$id])->getRow()->archived_at);
    }

    public function testUnarchiveAuditKeepsRealArchivedTimestamp(): void
    {
        $id = $this->createClient();
        $this->service()->archive($id);

        Services::injectMock('clock', new FrozenClock('2026-10-27T09:30:00+01:00'));
        $this->assertTrue($this->service()->unarchive($id));

        // Pôvodný okamih archivácie, nie čas odarchivovania (porovnanie ako timestamptz, offset závisí od TimeZone session).
        $audit = $this->owner()->query(
            "SELECT (before_data->>'archived_at')::timestamptz = '2026-10-25T10:00:00+02:00'::timestamptz AS same,
                    after_data->>'archived_at' AS a
               FROM audit_log WHERE entity_type = 'client' AND action = 'unarchive'",
        )->getRow();

        $this->assertSame('t', $audit->same);
        $this->assertNull($audit->a);
    }
}
