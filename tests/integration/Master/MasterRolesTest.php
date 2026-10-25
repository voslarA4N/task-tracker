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

    public function testClientsHourlyRateIsMoneyNumericAndStillUpdatable(): void
    {
        // IN-02: peniaze numeric(12,2), stĺpcový grant prežije ALTER TYPE.
        $col = $this->owner()->query(
            "SELECT numeric_precision AS p, numeric_scale AS s FROM information_schema.columns WHERE table_name = 'clients' AND column_name = 'hourly_rate'",
        )->getRow();
        $this->assertSame(12, (int) $col->p);
        $this->assertSame(2, (int) $col->s);

        $this->rw()->query("UPDATE clients SET hourly_rate = '9999999999.99'");
        $this->assertSame('9999999999.99', $this->owner()->query('SELECT hourly_rate FROM clients')->getRow()->hourly_rate);
    }

    public function testAuditLogIsInsertOnly(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE audit_log SET ip = 'x'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM audit_log'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('TRUNCATE audit_log'));
    }
}
