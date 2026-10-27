<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PgSql\Connection;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * Deterministicky dokaz, ze druhe spojenie caka na zamok citaca (INV-04).
 *
 * @internal
 */
#[Group('integration')]
final class CounterLockTest extends PgTestCase
{
    use IssueFixtures;
    use MasterFixtures;

    private const UPSERT = 'INSERT INTO invoice_counters (series_id, period_key, last_value) VALUES (%d, \'202610\', 1)
        ON CONFLICT (series_id, period_key) DO UPDATE SET last_value = invoice_counters.last_value + 1 RETURNING last_value';

    private int $seriesId = 0;

    /**
     * @var list<Connection>
     */
    private array $conns = [];

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();

        $s              = $this->issueSetup();
        $this->seriesId = $s['series'];
        $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
    }

    protected function tearDown(): void
    {
        foreach ($this->conns as $c) {
            @pg_query($c, 'ROLLBACK');
            pg_close($c);
        }
        $this->conns = [];
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    public function testSecondConnectionGetsSameNumberAfterRollback(): void
    {
        [$a, $b] = [$this->connect(), $this->connect()];

        $va = $this->lockAndGet($a);
        $this->assertSame(2, $va);

        pg_query($b, 'BEGIN');
        $this->assertTrue(pg_send_query($b, sprintf(self::UPSERT, $this->seriesId)));
        usleep(300_000);
        $this->assertTrue(pg_connection_busy($b), 'B must wait for the counter row lock held by A');

        pg_query($a, 'ROLLBACK');

        $this->assertSame($va, $this->finishUpsert($b));
        pg_query($b, 'ROLLBACK');
    }

    public function testSecondConnectionGetsNextNumberAfterCommit(): void
    {
        [$a, $b] = [$this->connect(), $this->connect()];

        $va = $this->lockAndGet($a);

        pg_query($b, 'BEGIN');
        $this->assertTrue(pg_send_query($b, sprintf(self::UPSERT, $this->seriesId)));
        usleep(300_000);
        $this->assertTrue(pg_connection_busy($b));

        pg_query($a, 'COMMIT');

        $this->assertSame($va + 1, $this->finishUpsert($b));
        pg_query($b, 'ROLLBACK');
    }

    private function connect(): Connection
    {
        $cfg = config('Database')->tests;
        $c   = pg_connect(sprintf(
            'host=%s port=%d dbname=%s user=%s password=%s',
            $cfg['hostname'],
            $cfg['port'],
            $cfg['database'],
            $cfg['username'],
            $cfg['password'],
        ), PGSQL_CONNECT_FORCE_NEW);
        $this->assertInstanceOf(Connection::class, $c);
        $this->conns[] = $c;

        return $c;
    }

    private function lockAndGet(Connection $c): int
    {
        pg_query($c, 'BEGIN');
        $r = pg_query($c, sprintf(self::UPSERT, $this->seriesId));
        $this->assertNotFalse($r);

        return (int) pg_fetch_result($r, 0, 0);
    }

    private function finishUpsert(Connection $c): int
    {
        $last = null;

        // Multi-statement: BEGIN a potom UPSERT, posledny vysledok nesie hodnotu.
        while (($r = pg_get_result($c)) !== false) {
            $last = $r;
        }
        $this->assertNotNull($last);

        return (int) pg_fetch_result($last, 0, 0);
    }
}
