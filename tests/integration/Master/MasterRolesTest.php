<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * Súhrnný dôkaz oprávnení app_rw na master tabuľkách (D-02, D-04, D-15, D-18).
 *
 * @internal
 */
#[Group('integration')]
final class MasterRolesTest extends PgTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        $this->createEntity('SK');
        $this->createClient();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        parent::tearDown();
    }

    public function testEntitiesCountryAndCurrencyCannotBeUpdated(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE entities SET country = 'PL'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE entities SET currency = 'PLN'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE entities SET name_internal = 'x', country = 'CZ'"));
    }

    public function testEntitiesCannotBeDeleted(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM entities'));
    }

    public function testEntitiesNameCanBeUpdated(): void
    {
        $this->rw()->query("UPDATE entities SET name_internal = 'x'");
        $this->assertSame('x', $this->owner()->query('SELECT name_internal FROM entities')->getRow()->name_internal);
    }

    public function testEntityVersionsAreInsertOnly(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE entity_versions SET city = 'x'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM entity_versions'));
    }

    public function testInvoiceSeriesCannotMoveOrBeDeleted(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE invoice_series SET entity_id = 1'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM invoice_series'));
    }

    public function testClientsCannotBeDeletedButCanBeUpdated(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM clients'));
        $this->rw()->query("UPDATE clients SET name = 'x'");
        $this->assertSame('x', $this->owner()->query('SELECT name FROM clients')->getRow()->name);
    }

    public function testAuditLogIsInsertOnly(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE audit_log SET ip = 'x'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM audit_log'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('TRUNCATE audit_log'));
    }
}
