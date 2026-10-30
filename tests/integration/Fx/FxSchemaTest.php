<?php

declare(strict_types=1);

namespace Tests\Integration\Fx;

use App\Libraries\Fx\FxSettingsService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FxFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class FxSchemaTest extends PgTestCase
{
    use MasterFixtures;
    use IssueFixtures;
    use FxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->restoreFxSettings();
        $this->resetMasterTables();
        parent::tearDown();
    }

    public function testSeedDefaults(): void
    {
        $rows = $this->owner()->query('SELECT country, source, date_basis, day_rule FROM fx_country_settings ORDER BY country')->getResultArray();
        $this->assertSame([
            ['country' => 'CZ', 'source' => 'CNB', 'date_basis' => 'delivery_date', 'day_rule' => 'on_or_before'],
            ['country' => 'PL', 'source' => 'NBP', 'date_basis' => 'delivery_date', 'day_rule' => 'previous_business_day'],
            ['country' => 'SK', 'source' => 'ECB', 'date_basis' => 'delivery_date', 'day_rule' => 'previous_business_day'],
        ], $rows);
    }

    public function testSettingsPrivileges(): void
    {
        $this->rw()->query('BEGIN');

        try {
            $this->rw()->query("UPDATE fx_country_settings SET source = 'ECB' WHERE country = 'PL'");
        } finally {
            $this->rw()->query('ROLLBACK');
        }

        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE fx_country_settings SET country = 'XX' WHERE country = 'PL'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query("INSERT INTO fx_country_settings (country, source, date_basis, day_rule, updated_at) VALUES ('SK', 'ECB', 'issue_date', 'on_or_before', now())"));
        $this->assertPermissionDenied(fn () => $this->rw()->query("DELETE FROM fx_country_settings WHERE country = 'PL'"));
    }

    public function testSettingsCheck(): void
    {
        try {
            $this->owner()->query("UPDATE fx_country_settings SET source = 'XYZ' WHERE country = 'PL'");
            $this->fail('Expected check violation');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('check', $e->getMessage());
        }
    }

    public function testCacheConstraints(): void
    {
        $insert = "INSERT INTO exchange_rate_cache (source, currency, bound_date, valid_for, rate, rate_published, direction, fetched_at)
                   VALUES ('NBP', 'EUR', '2026-10-20', '2026-10-20', 4.1, '4.1', 'multiply', now())";
        $this->rw()->query($insert);
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM exchange_rate_cache'));
        $this->assertSame('check', $this->violation(str_replace("'2026-10-20', '2026-10-20', 4.1, '4.1'", "'2026-10-21', '2026-10-21', 0, '0'", $insert)));
        $this->assertSame('unique', $this->violation($insert));
        $this->assertSame('foreign', $this->violation(str_replace("'EUR'", "'USD'", $insert)));
    }

    public function testServiceReads(): void
    {
        $svc = new FxSettingsService($this->rw());
        $this->assertSame('NBP', $svc->forCountry('PL')['source']);
        $this->assertSame(['SK', 'PL', 'CZ'], array_column($svc->all(), 'country'));

        $this->expectException(InvalidArgumentException::class);
        $svc->forCountry('DE');
    }

    public function testResetEmptiesCacheButNotSettings(): void
    {
        $this->seedRate('NBP', 'EUR', '2026-10-20', '2026-10-20', '4.1000');
        $this->resetMasterTables();
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS n FROM exchange_rate_cache')->getRow()->n);
        $this->assertSame(3, (int) $this->owner()->query('SELECT count(*) AS n FROM fx_country_settings')->getRow()->n);
        $this->assertSame(3, (int) $this->owner()->query('SELECT count(*) AS n FROM currencies')->getRow()->n);
    }

    private function violation(string $sql): string
    {
        try {
            $this->rw()->query($sql);
        } catch (DatabaseException $e) {
            $m = $e->getMessage();

            return match (true) {
                str_contains($m, 'check constraint') => 'check',
                str_contains($m, 'unique')           => 'unique',
                str_contains($m, 'foreign key')      => 'foreign',
                default                              => $m,
            };
        }

        return 'none';
    }
}
