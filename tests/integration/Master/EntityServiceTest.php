<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\EntityService;
use App\Libraries\Master\EntityVersionResolver;
use App\Libraries\Master\VersionNotFound;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class EntityServiceTest extends PgTestCase
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

    private function count(string $table, string $where = 'true'): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM {$table} WHERE {$where}")->getRow()->c;
    }

    public function testCreateSlovakEntity(): void
    {
        $id = $this->createEntity('SK');

        $entity = $this->owner()->query('SELECT * FROM entities WHERE id = ?', [$id])->getRow();
        $this->assertSame('SK', $entity->country);
        $this->assertSame('EUR', $entity->currency);

        $this->assertSame(1, $this->count('entities'));
        $this->assertSame(1, $this->count('entity_versions', "valid_from = '2026-01-01' AND vat_regime = 'sk_non_vat'"));
        $this->assertSame(1, $this->count('invoice_series', "kind = 'invoice' AND format = 'RRRRMM###' AND initial_number = 1"));
        $this->assertSame(1, $this->count('invoice_series', "kind = 'credit_note' AND format = 'DB-RRRRMM###' AND initial_number = 1"));
        $this->assertSame(4, $this->count('audit_log', "action = 'create'"));
        $this->assertSame(1, $this->count('audit_log', "entity_type = 'entity'"));
        $this->assertSame(1, $this->count('audit_log', "entity_type = 'entity_version'"));
        $this->assertSame(2, $this->count('audit_log', "entity_type = 'invoice_series'"));
    }

    public function testCreatePolishEntity(): void
    {
        $id = $this->createEntity('PL');

        $entity = $this->owner()->query('SELECT * FROM entities WHERE id = ?', [$id])->getRow();
        $this->assertSame('PL', $entity->country);
        $this->assertSame('PLN', $entity->currency);
        $this->assertSame(1, $this->count('entity_versions', "vat_regime = 'pl_exempt_113_1'"));
        $this->assertSame(2, $this->count('invoice_series'));
        $this->assertSame(4, $this->count('audit_log'));
    }

    public function testRollbackWhenAuditFails(): void
    {
        $failing = new class ($this->rw()) extends AuditLogger {
            private int $calls = 0;

            public function record(string $entityType, int $entityId, string $action, ?array $before, ?array $after): void
            {
                if (++$this->calls === 3) {
                    throw new RuntimeException('audit failed');
                }

                parent::record($entityType, $entityId, $action, $before, $after);
            }
        };

        try {
            (new EntityService($this->rw(), $failing))->create(
                ['name_internal' => 'X', 'country' => 'SK', 'currency' => 'EUR', 'active_from' => '2026-01-01', 'active_to' => null],
                [
                    'trade_name' => 'X', 'address_line1' => 'A', 'address_line2' => null, 'postal_code' => '81101', 'city' => 'B',
                    'company_id' => '12345678', 'regon' => null, 'tax_id' => null, 'vat_id' => null, 'registry_note' => null,
                    'email' => 'x@example.test', 'phone' => null, 'doc_language' => 'sk', 'vat_regime' => 'sk_non_vat', 'due_days' => '14',
                ],
            );
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame('audit failed', $e->getMessage());
        }

        foreach (['entities', 'entity_versions', 'invoice_series', 'audit_log'] as $table) {
            $this->assertSame(0, $this->count($table), $table);
        }
    }

    public function testColumnGrantsProtectCountryAndCurrency(): void
    {
        $id = $this->createEntity('SK');
        $rw = $this->rw();

        $this->assertPermissionDenied(static fn () => $rw->query("UPDATE entities SET country = 'PL'"));
        $this->assertPermissionDenied(static fn () => $rw->query("UPDATE entities SET currency = 'PLN'"));
        $this->assertPermissionDenied(static fn () => $rw->query("UPDATE entities SET active_to = '2027-01-01', currency = 'PLN'"));
        $this->assertPermissionDenied(static fn () => $rw->query('DELETE FROM entities'));
        $rw->query("UPDATE entities SET active_to = '2027-01-01' WHERE id = ?", [$id]);
        $this->assertSame(1, $this->count('entities', "active_to = '2027-01-01' AND country = 'SK' AND currency = 'EUR'"));

        $this->assertPermissionDenied(static fn () => $rw->query("UPDATE entity_versions SET trade_name = 'x'"));
        $this->assertPermissionDenied(static fn () => $rw->query('DELETE FROM entity_versions'));

        $this->assertPermissionDenied(static fn () => $rw->query("UPDATE invoice_series SET kind = 'invoice'"));
        $rw->query("UPDATE invoice_series SET format = 'RRRR###' WHERE kind = 'invoice'");
        $this->assertSame(1, $this->count('invoice_series', "format = 'RRRR###'"));
    }

    public function testVersionResolver(): void
    {
        $id       = $this->createEntity('SK');
        $resolver = new EntityVersionResolver($this->rw());

        try {
            $resolver->atDate($id, '2025-12-31');
            $this->fail('Expected VersionNotFound');
        } catch (VersionNotFound) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('2026-01-01', $resolver->atDate($id, '2026-01-01')['valid_from']);
        $this->assertSame('2026-01-01', $resolver->atDate($id, '2026-10-25')['valid_from']);
        $this->assertSame('2026-01-01', $resolver->current($id, '2025-06-01')['valid_from']);
    }
}
