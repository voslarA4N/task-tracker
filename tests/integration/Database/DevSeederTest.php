<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\Seeds\DevSeeder;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class DevSeederTest extends PgTestCase
{
    use MasterFixtures;

    private const TABLES = ['entities', 'entity_versions', 'entity_bank_accounts', 'invoice_series', 'clients', 'audit_log'];

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

    private function total(string $table, string $where = 'true'): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM {$table} WHERE {$where}")->getRow()->c;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $out = [];

        foreach (self::TABLES as $t) {
            $out[$t] = $this->total($t);
        }

        return $out;
    }

    public function testSeedsSampleData(): void
    {
        (new DevSeeder(config('Database')))->seedWith($this->owner());

        $this->assertSame(2, $this->total('entities'));
        $this->assertSame(1, $this->total('entities', "country = 'SK' AND currency = 'EUR'"));
        $this->assertSame(1, $this->total('entities', "country = 'PL' AND currency = 'PLN'"));
        $this->assertSame(1, $this->total('entity_versions', "vat_regime = 'sk_non_vat'"));
        $this->assertSame(1, $this->total('entity_versions', "vat_regime = 'pl_exempt_113_1'"));
        $this->assertSame(3, $this->total('entity_bank_accounts'));
        $this->assertSame(2, $this->total('entity_bank_accounts', 'is_default'));
        $this->assertSame(4, $this->total('invoice_series'));
        $this->assertSame(1, $this->total('clients', "hourly_rate = 27.00 AND currency = 'EUR' AND portal_name = 'Taskman'"));
    }

    public function testIsIdempotent(): void
    {
        $seeder = new DevSeeder(config('Database'));
        $seeder->seedWith($this->owner());
        $before = $this->counts();
        $seeder->seedWith($this->owner());

        $this->assertSame($before, $this->counts());
    }

    public function testProductionStillRefused(): void
    {
        $this->expectException(RuntimeException::class);
        DevSeeder::assertAllowed('production');
    }
}
