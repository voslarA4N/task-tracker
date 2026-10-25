<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\ClientService;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class ClientServiceTest extends PgTestCase
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

    private function service(): ClientService
    {
        return new ClientService($this->rw(), new AuditLogger($this->rw()));
    }

    private function audits(string $action): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM audit_log WHERE entity_type = ? AND action = ?', ['client', $action])->getRow()->c;
    }

    public function testCreateAndFind(): void
    {
        $id     = $this->createClient(['cc_emails' => ['a@x.sk', 'b@x.sk']]);
        $client = $this->service()->find($id);

        $this->assertNotNull($client);
        $this->assertIsString($client['hourly_rate']);
        $this->assertSame('27.00', $client['hourly_rate']);
        $this->assertSame(['a@x.sk', 'b@x.sk'], $client['cc_emails']);
        $this->assertTrue($client['counts_toward_limit']);
        $this->assertSame('All4Net', $client['name']);
        $this->assertSame(1, $this->audits('create'));
    }

    public function testFindUnknownReturnsNull(): void
    {
        $this->assertNull($this->service()->find(999));
    }

    public function testUpdateAuditsOnlyChangedFields(): void
    {
        $id = $this->createClient();

        $values                = $this->service()->find($id) ?? [];
        $values['hourly_rate'] = '30.00';
        $this->assertTrue($this->service()->update($id, $values));

        $row = $this->owner()->query("SELECT before_data, after_data FROM audit_log WHERE entity_type = 'client' AND action = 'update'")->getRow();
        $this->assertSame(['hourly_rate' => '27.00'], json_decode((string) $row->before_data, true));
        $this->assertSame(['hourly_rate' => '30.00'], json_decode((string) $row->after_data, true));
        $this->assertSame('30.00', $this->service()->find($id)['hourly_rate']);
    }

    public function testUpdateWithoutChangeReturnsFalse(): void
    {
        $id = $this->createClient();
        $this->assertFalse($this->service()->update($id, $this->service()->find($id) ?? []));
        $this->assertSame(0, $this->audits('update'));
    }

    public function testUpdateUnknownThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->update(999, []);
    }

    public function testListAllSortedByNameAndArchivedFilter(): void
    {
        $this->createClient(['name' => 'Zeta']);
        $b = $this->createClient(['name' => 'Alfa']);
        $this->owner()->query('UPDATE clients SET archived_at = now() WHERE id = ?', [$b]);

        $this->assertSame(['Zeta'], array_column($this->service()->listAll(false), 'name'));
        $this->assertSame(['Alfa'], array_column($this->service()->listAll(true), 'name'));
    }

    public function testRwCannotDelete(): void
    {
        $this->createClient();
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM clients'));
    }

    public function testRwCannotUpdateCreatedAt(): void
    {
        $this->createClient();
        $this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE clients SET created_at = now()'));
    }

    public function testEuB2bWithoutVatViolatesCheck(): void
    {
        $this->expectException(DatabaseException::class);
        $this->owner()->query(<<<'SQL'
            INSERT INTO clients (name, address_line1, postal_code, city, country, identifier_type, invoice_email, hourly_rate, currency, billing_mode, created_at, updated_at)
            VALUES ('X', 'A', '1', 'B', 'DE', 'none', 'x@x.sk', 10, 'EUR', 'eu_b2b', now(), now())
            SQL);
    }

    public function testCountsTowardLimitDefaultsToTrue(): void
    {
        $this->owner()->query(<<<'SQL'
            INSERT INTO clients (name, address_line1, postal_code, city, country, identifier_type, invoice_email, hourly_rate, currency, billing_mode, created_at, updated_at)
            VALUES ('X', 'A', '1', 'B', 'SK', 'none', 'x@x.sk', 10, 'EUR', 'domestic', now(), now())
            SQL);
        $this->assertTrue((bool) $this->owner()->query('SELECT counts_toward_limit AS c FROM clients')->getRow()->c);
    }
}
