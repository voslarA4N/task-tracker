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

    public function testLegalTextsAllowOnlyBodyColumnsUpdateAndInsert(): void
    {
        $id = (int) $this->owner()->query("SELECT id FROM legal_texts WHERE country = 'SK' AND language = 'sk' AND key = 'vat_note'")->getRow()->id;

        try {
            $this->rw()->query("UPDATE legal_texts SET body = 'Nie som platiteľ DPH.', provisional = false, revision = revision + 1, updated_at = now() WHERE id = " . $id);
            $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE legal_texts SET key = 'other_key' WHERE id = " . $id));
            $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE legal_texts SET language = 'en' WHERE id = " . $id));
            $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE legal_texts SET country = 'CZ' WHERE id = " . $id));
            $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM legal_texts WHERE id = ' . $id));

            $this->rw()->query("INSERT INTO legal_texts (country, vat_regime, billing_mode, language, key, body, provisional, updated_at) VALUES ('SK','sk_non_vat','domestic','sk','vat_note','x', true, now())");
            $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM legal_texts WHERE billing_mode = 'domestic' AND country = 'SK'")->getRow()->c);

            $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE legal_profiles SET vat_code = 'x'"));
        } finally {
            $this->owner()->query("DELETE FROM legal_texts WHERE country = 'SK' AND billing_mode = 'domestic'");
            $this->owner()->query("UPDATE legal_texts SET provisional = true, revision = 1 WHERE id = " . $id);
        }
    }

    public function testBodyCheckAllows2000ButNot2001(): void
    {
        try {
            $this->owner()->query("INSERT INTO legal_texts (country, vat_regime, billing_mode, language, key, body, provisional, updated_at) VALUES ('SK','sk_non_vat','domestic','sk','vat_note', repeat('a', 2000), true, now())");
            $this->assertTrue(true);

            try {
                $this->owner()->query("INSERT INTO legal_texts (country, vat_regime, billing_mode, language, key, body, provisional, updated_at) VALUES ('SK','sk_non_vat','non_eu','sk','vat_note', repeat('a', 2001), true, now())");
                $this->fail('CHECK expected');
            } catch (\CodeIgniter\Database\Exceptions\DatabaseException) {
                $this->assertTrue(true);
            }
        } finally {
            $this->owner()->query("DELETE FROM legal_texts WHERE country = 'SK' AND billing_mode IS NOT NULL");
        }
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
