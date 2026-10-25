<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\BankAccountService;
use App\Libraries\Master\ClientService;
use App\Libraries\Master\EntityService;
use App\Libraries\Master\SeriesService;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * WR-02: audit "before" a rozhodnutia sa čítajú v transakcii nad uzamknutým riadkom.
 * Dôkaz: aj pri zmene bez efektu (no-op) drží vonkajšia transakcia zámok, ktorý iná session nedostane (NOWAIT).
 *
 * @internal
 */
#[Group('integration')]
final class RowLockTest extends PgTestCase
{
    use MasterFixtures;

    private const SK_IBAN = 'SK3112000000198742637541';

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        while ($this->rw()->transDepth > 0) {
            $this->rw()->transRollback();
        }

        $this->rw()->resetTransStatus()->transException(false);
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function assertRowLocked(string $table, int $id): void
    {
        try {
            $this->owner()->query('SELECT id FROM ' . $table . ' WHERE id = :id: FOR UPDATE NOWAIT', ['id' => $id]);
        } catch (DatabaseException $e) {
            $this->assertMatchesRegularExpression('/could not obtain lock|55P03/i', $e->getMessage());

            return;
        }

        $this->fail('Row ' . $table . '#' . $id . ' is not locked by the service transaction.');
    }

    public function testClientUpdateLocksRowEvenWithoutChange(): void
    {
        $id      = $this->createClient();
        $service = new ClientService($this->rw(), new AuditLogger($this->rw()));
        $values  = $service->find($id);
        $this->assertNotNull($values);

        $this->rw()->transStart();
        $this->assertFalse($service->update($id, $values));
        $this->assertRowLocked('clients', $id);
    }

    public function testEntityUpdateBaseLocksRowEvenWithoutChange(): void
    {
        $id = $this->createEntity();

        $this->rw()->transStart();
        $changed = (new EntityService($this->rw(), new AuditLogger($this->rw())))
            ->updateBase($id, ['name_internal' => 'Moja firma SK', 'active_from' => '2026-01-01', 'active_to' => null]);

        $this->assertFalse($changed);
        $this->assertRowLocked('entities', $id);
    }

    public function testBankAccountSetDefaultLocksEntityAndAccount(): void
    {
        $entityId = $this->createEntity();
        $service  = new BankAccountService($this->rw(), new AuditLogger($this->rw()));
        $account  = $service->add($entityId, ['label' => 'Ucet', 'iban' => self::SK_IBAN, 'swift' => null, 'currency' => 'EUR', 'is_default' => true]);

        $this->rw()->transStart();
        $service->setDefault($entityId, $account);

        $this->assertRowLocked('entities', $entityId);
        $this->assertRowLocked('entity_bank_accounts', $account);
    }

    public function testSeriesUpdateLocksSeriesRows(): void
    {
        $entityId = $this->createEntity();
        $series   = (int) $this->owner()->query("SELECT id FROM invoice_series WHERE entity_id = ? AND kind = 'invoice'", [$entityId])->getRow()->id;

        $this->rw()->transStart();
        (new SeriesService($this->rw(), new AuditLogger($this->rw())))
            ->update($entityId, 'invoice', EntityService::DEFAULT_INVOICE_FORMAT, '1');

        $this->assertRowLocked('invoice_series', $series);
    }
}
