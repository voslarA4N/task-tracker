<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Support\GrantsPrivileges;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * Dokaz insert-only opravneni app_rw (Success Criteria 1).
 *
 * @internal
 */
#[Group('integration')]
final class DbRolesTest extends PgTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $db = self::ownerConn();
        $db->query('DROP TABLE IF EXISTS _probe_insert_only, _probe_mutable');
        $ddl = 'id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, note text NOT NULL';
        $db->query("CREATE TABLE _probe_insert_only ($ddl)");
        $db->query("CREATE TABLE _probe_mutable ($ddl)");

        // Rovnaka cesta ako v migracii: trait nad objektom s $this->db.
        $migration = new class ($db) {
            use GrantsPrivileges;

            public function __construct(protected $db)
            {
            }

            public function run(string $t): void
            {
                $this->grantMutable($t);
            }
        };
        $migration->run('_probe_mutable');
    }

    public static function tearDownAfterClass(): void
    {
        self::ownerConn()->query('DROP TABLE IF EXISTS _probe_insert_only, _probe_mutable');
        parent::tearDownAfterClass();
    }

    public function testConnectionsUseExpectedRoles(): void
    {
        $this->assertSame('app_rw', $this->rw()->query('SELECT current_user AS u')->getRow()->u);
        $this->assertSame('app_owner', $this->owner()->query('SELECT current_user AS u')->getRow()->u);
    }

    public function testRwIsNotPrivileged(): void
    {
        $row = $this->rw()->query('SELECT (rolsuper OR rolcreaterole OR rolcreatedb) AS p FROM pg_roles WHERE rolname = current_user')->getRow();
        $this->assertContains($row->p, [false, 'f']);
    }

    public function testRwCanInsertAndSelect(): void
    {
        $this->rw()->query("INSERT INTO _probe_insert_only (note) VALUES ('a')");
        $this->assertGreaterThanOrEqual(1, $this->rw()->query('SELECT * FROM _probe_insert_only')->getNumRows());
    }

    public function testRwCannotUpdateDeleteTruncateInsertOnly(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE _probe_insert_only SET note='b'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM _probe_insert_only'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('TRUNCATE _probe_insert_only'));
    }

    public function testRwCannotRunDdl(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query('CREATE TABLE _probe_ddl (id int)'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DROP TABLE _probe_insert_only'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('ALTER TABLE _probe_insert_only ADD COLUMN x int'));
    }

    public function testMutableTableAllowsUpdateDeleteButNotTruncate(): void
    {
        $this->rw()->query("INSERT INTO _probe_mutable (note) VALUES ('a')");
        $this->rw()->query("UPDATE _probe_mutable SET note='b'");
        $this->rw()->query('DELETE FROM _probe_mutable');
        $this->assertPermissionDenied(fn () => $this->rw()->query('TRUNCATE _probe_mutable'));
    }

    public function testNumericComesBackAsString(): void
    {
        $v = $this->rw()->query('SELECT 1.25::numeric(7,2) * 27.00::numeric(12,2) AS v')->getRow()->v;
        $this->assertIsString($v);
        $this->assertSame('33.7500', $v);
    }

    public function testMigrationsTableOwnedByOwner(): void
    {
        $row = $this->owner()->query("SELECT tableowner FROM pg_tables WHERE tablename = 'migrations' AND schemaname = 'public'")->getRow();
        $this->assertNotNull($row, 'migrations table should exist after test:migrate');
        $this->assertSame('app_owner', $row->tableowner);
    }
}
