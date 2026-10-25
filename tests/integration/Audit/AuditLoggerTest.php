<?php

declare(strict_types=1);

namespace Tests\Integration\Audit;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Config\Services;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class AuditLoggerTest extends PgTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
        $this->owner()->query('TRUNCATE demo_calculations RESTART IDENTITY');
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        $this->owner()->query('TRUNCATE demo_calculations RESTART IDENTITY');
        Services::reset();
        parent::tearDown();
    }

    private function count(string $table): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM {$table}")->getRow()->c;
    }

    public function testRecordWritesFullRow(): void
    {
        (new AuditLogger($this->rw()))->record('entity', 5, 'create', null, ['trade_name' => 'Ťažký Ľad s.r.o.', 'rate' => '27.00']);

        $row = $this->owner()->query(
            "SELECT user_id, ip, entity_type, entity_id, action, before_data, after_data->>'trade_name' AS tn, after_data->>'rate' AS rate,
                    jsonb_typeof(after_data->'rate') AS rt, occurred_at = '2026-10-25T10:00:00+02:00'::timestamptz AS same_time
             FROM audit_log",
        )->getRowArray();

        $this->assertNotNull($row);
        $this->assertNull($row['user_id']);
        $this->assertSame('cli', $row['ip']);
        $this->assertSame('entity', $row['entity_type']);
        $this->assertSame(5, (int) $row['entity_id']);
        $this->assertSame('create', $row['action']);
        $this->assertNull($row['before_data']);
        $this->assertSame('Ťažký Ľad s.r.o.', $row['tn']);
        $this->assertSame('27.00', $row['rate']);
        $this->assertSame('string', $row['rt']);
        $this->assertContains($row['same_time'], [true, 't']);
    }

    public function testRecordChangeStoresOnlyChangedKeys(): void
    {
        $logger = new AuditLogger($this->rw());

        $this->assertTrue($logger->recordChange('client', 1, 'update', ['a' => '1', 'b' => '2'], ['a' => '1', 'b' => '3']));
        $row = $this->owner()->query('SELECT before_data::text AS b, after_data::text AS a FROM audit_log')->getRowArray();
        $this->assertSame('{"b": "2"}', $row['b']);
        $this->assertSame('{"b": "3"}', $row['a']);

        $this->assertFalse($logger->recordChange('client', 1, 'update', ['a' => '1'], ['a' => '1']));
        $this->assertSame(1, $this->count('audit_log'));
    }

    public function testRollbackRemovesChangeAndAudit(): void
    {
        $rw = $this->rw();
        $rw->transException(true)->transStart();

        try {
            $rw->query("INSERT INTO demo_calculations (hours, rate, amount, created_at) VALUES (1.00, 2.00, 2.00, now())");
            (new AuditLogger($rw))->record('entity', 1, 'create', null, ['x' => '1']);

            throw new RuntimeException('boom');
        } catch (RuntimeException) {
            $rw->transRollback();
        } finally {
            $rw->transException(false);
        }

        $this->assertSame(0, $this->count('demo_calculations'));
        $this->assertSame(0, $this->count('audit_log'));
    }

    public function testAppRwCannotUpdateOrDelete(): void
    {
        (new AuditLogger($this->rw()))->record('entity', 1, 'create', null, ['x' => '1']);

        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE audit_log SET action = 'x'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM audit_log'));
    }

    public function testInvalidEntityTypeOrActionRejected(): void
    {
        $logger = new AuditLogger($this->rw());

        try {
            $logger->record('Entity', 1, 'create', null, null);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $logger->record('entity', 1, 'up-date', null, null);
    }
}
