<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use CodeIgniter\Database\Exceptions\DatabaseException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class CurrenciesSchemaTest extends PgTestCase
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

    public function testSeed(): void
    {
        $rows = $this->owner()->query('SELECT code, decimals, active FROM currencies ORDER BY sort_order')->getResultArray();
        $this->assertSame(['EUR', 'PLN', 'CZK'], array_map(static fn ($r) => trim((string) $r['code']), $rows));

        foreach ($rows as $r) {
            $this->assertSame(2, (int) $r['decimals']);
            $this->assertTrue($r['active'] === true || $r['active'] === 't');
        }
    }

    public function testForeignKeysReplaceChecks(): void
    {
        foreach (['entities', 'clients', 'entity_bank_accounts', 'billing_allocations', 'invoices'] as $table) {
            $checks = $this->owner()->query(
                "SELECT conname FROM pg_constraint WHERE conrelid = '{$table}'::regclass AND contype = 'c'
                 AND pg_get_constraintdef(oid) LIKE '%currency%' AND pg_get_constraintdef(oid) LIKE '%EUR%'",
            )->getResultArray();
            $this->assertSame([], $checks, $table);

            $fk = $this->owner()->query(
                "SELECT 1 FROM pg_constraint WHERE conrelid = '{$table}'::regclass AND contype = 'f'
                 AND conname = '{$table}_currency_fk' AND confrelid = 'currencies'::regclass",
            )->getRowArray();
            $this->assertNotNull($fk, $table);
        }
    }

    public function testUnknownCurrencyRejectedByFk(): void
    {
        try {
            $this->createClient(['currency' => 'USD']);
            $this->fail('Expected FK violation');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('currency', $e->getMessage());
        }
    }

    public function testNewCurrencyWithoutSchemaChange(): void
    {
        $this->owner()->query("INSERT INTO currencies (code, decimals, active, sort_order) VALUES ('HUF', 2, true, 4)");

        try {
            $id = $this->createClient(['currency' => 'HUF']);
            $this->assertGreaterThan(0, $id);
        } finally {
            $this->resetMasterTables();
            $this->owner()->query("DELETE FROM currencies WHERE code = 'HUF'");
        }
    }

    public function testAppRwIsReadOnly(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query("INSERT INTO currencies (code, decimals, active, sort_order) VALUES ('HUF', 2, true, 9)"));
        $this->assertNotNull($this->rw()->query('SELECT 1 FROM currencies LIMIT 1')->getRowArray());
    }
}
