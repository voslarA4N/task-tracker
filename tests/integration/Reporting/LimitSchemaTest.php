<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use CodeIgniter\Database\Exceptions\DatabaseException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class LimitSchemaTest extends PgTestCase
{
    use MasterFixtures;

    private const INSERT = "INSERT INTO turnover_limits (country, year, name, amount, currency, calc_method, thresholds, created_at, updated_at)
                            VALUES ('SK', 2030, 'X', 100.00, 'EUR', 'calendar_year', '{80,100}', now(), now())";

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner()->query("DELETE FROM turnover_limits WHERE year = 2030");
    }

    protected function tearDown(): void
    {
        $this->owner()->query("DELETE FROM turnover_limits WHERE year = 2030");
        parent::tearDown();
    }

    private function violation(string $sql): string
    {
        $db = $this->owner();
        $db->query('BEGIN');

        try {
            $db->query($sql);
        } catch (DatabaseException $e) {
            $m = $e->getMessage();

            return match (true) {
                str_contains($m, 'check constraint') => 'check',
                str_contains($m, 'unique') || str_contains($m, 'duplicate key') => 'unique',
                str_contains($m, 'foreign key') => 'foreign',
                default => $m,
            };
        } finally {
            $db->query('ROLLBACK');
        }

        return 'none';
    }

    public function testSeedRowsOnEmptyTable(): void
    {
        $db = $this->owner();
        $db->query('BEGIN');

        try {
            $db->query('DELETE FROM turnover_limits');
            $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-11-02-000001_CreateTurnoverLimits.php');
            $this->assertNotFalse($migration);
            $this->assertSame(1, preg_match('/(INSERT INTO turnover_limits.*?WHERE NOT EXISTS \(SELECT 1 FROM turnover_limits\))/s', $migration, $m));
            $db->query($m[1]);
            $rows = $db->query("SELECT country, year, name, amount::text AS amount, trim(currency) AS currency, calc_method, array_to_string(thresholds, ',') AS t, is_seed FROM turnover_limits ORDER BY country, amount")->getResultArray();
            $this->assertCount(4, $rows);
            $this->assertSame(['PL', 2026, '240000.00', 'PLN', 'pro_rata_first_year', '80,100'], [$rows[0]['country'], (int) $rows[0]['year'], $rows[0]['amount'], $rows[0]['currency'], $rows[0]['calc_method'], $rows[0]['t']]);
            $this->assertSame(['49790.00', 'rolling_12m', '80,100'], [$rows[1]['amount'], $rows[1]['calc_method'], $rows[1]['t']]);
            $this->assertSame(['50000.00', 'calendar_year', '80,100'], [$rows[2]['amount'], $rows[2]['calc_method'], $rows[2]['t']]);
            $this->assertSame(['62500.00', 'calendar_year', '100'], [$rows[3]['amount'], $rows[3]['calc_method'], $rows[3]['t']]);

            foreach ($rows as $row) {
                $this->assertTrue($row['is_seed'] === true || $row['is_seed'] === 't');
            }

            // Druhe spustenie na neprazdnej tabulke nic nepridá.
            $db->query($m[1]);
            $this->assertSame(4, (int) $db->query('SELECT count(*) AS c FROM turnover_limits')->getRow()->c);
        } finally {
            $db->query('ROLLBACK');
        }
    }

    public function testChecks(): void
    {
        $this->assertSame('none', $this->violation(self::INSERT));
        $bad = [
            str_replace("'SK'", "'DE'", self::INSERT),
            str_replace('2030', '1999', self::INSERT),
            str_replace('100.00', '0', self::INSERT),
            str_replace("'calendar_year'", "'monthly'", self::INSERT),
            str_replace("'{80,100}'", "'{}'", self::INSERT),
            str_replace("'{80,100}'", "'{10,20,30,40}'", self::INSERT),
            str_replace("'{80,100}'", "'{80,NULL}'", self::INSERT),
        ];

        foreach ($bad as $sql) {
            $this->assertSame('check', $this->violation($sql), $sql);
        }

        $this->assertSame('foreign', $this->violation(str_replace("'EUR'", "'USD'", self::INSERT)));
    }

    public function testUnique(): void
    {
        $this->owner()->query(self::INSERT);
        $this->assertSame('unique', $this->violation(self::INSERT));
    }

    public function testPrivileges(): void
    {
        $this->resetMasterTables();
        $entity = $this->createEntity('SK');
        $rw     = $this->rw();
        $rw->query('BEGIN');

        try {
            $rw->query(self::INSERT);
            $rw->query("UPDATE turnover_limits SET amount = 200, thresholds = '{50}', updated_at = now() WHERE year = 2030");
            $rw->query("DELETE FROM turnover_limits WHERE year = 2030");
            $rw->query("INSERT INTO entity_opening_turnover (entity_id, year, amount, created_at, updated_at) VALUES ({$entity}, 2026, 10.00, now(), now())");
            $rw->query("UPDATE entity_opening_turnover SET amount = 20, note = 'x', updated_at = now() WHERE entity_id = {$entity}");
            $rw->query("DELETE FROM entity_opening_turnover WHERE entity_id = {$entity}");
        } finally {
            $rw->query('ROLLBACK');
        }

        $this->assertPermissionDenied(fn () => $rw->query('UPDATE turnover_limits SET created_at = now()'));
        $this->assertPermissionDenied(fn () => $rw->query('UPDATE entity_opening_turnover SET entity_id = entity_id + 1'));
        $this->resetMasterTables();
    }

    public function testIndexExists(): void
    {
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM pg_indexes WHERE indexname = 'invoices_turnover_idx'")->getRow()->c);
    }
}
