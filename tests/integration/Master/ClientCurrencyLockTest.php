<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\ClientCurrencyLocked;
use App\Libraries\Master\ClientService;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class ClientCurrencyLockTest extends PgTestCase
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

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private function changed(int $id, array $over): array
    {
        $client = $this->service()->find($id);
        unset($client['id'], $client['archived_at'], $client['created_at'], $client['updated_at']);

        return $over + $client;
    }

    private function currency(int $id): string
    {
        return trim((string) $this->owner()->query('SELECT currency FROM clients WHERE id = ?', [$id])->getRow()->currency);
    }

    public function testChangeAllowedWithoutTasks(): void
    {
        $id = $this->createClient();
        $this->service()->update($id, $this->changed($id, ['currency' => 'PLN']));

        $this->assertSame('PLN', $this->currency($id));
    }

    public function testChangeAllowedWithTaskWithoutAllocation(): void
    {
        $id = $this->createClient();
        $this->createTask($id);
        $this->service()->update($id, $this->changed($id, ['currency' => 'PLN']));

        $this->assertSame('PLN', $this->currency($id));
    }

    public function testChangeRejectedWithUnlockedAllocation(): void
    {
        $id = $this->createClient();
        $this->createAllocation($this->createTask($id), '5.00', '2026-10');

        $this->expectException(ClientCurrencyLocked::class);

        try {
            $this->service()->update($id, $this->changed($id, ['currency' => 'PLN']));
        } finally {
            $this->assertSame('EUR', $this->currency($id));
        }
    }

    public function testChangeRejectedWithLockedAllocation(): void
    {
        $id    = $this->createClient();
        $alloc = $this->createAllocation($this->createTask($id), '5.00', '2026-10');
        $this->lockAllocation($alloc);

        $this->expectException(ClientCurrencyLocked::class);
        $this->service()->update($id, $this->changed($id, ['currency' => 'PLN']));
    }

    public function testOtherChangeAllowedWithAllocations(): void
    {
        $id = $this->createClient();
        $this->createAllocation($this->createTask($id), '5.00', '2026-10');

        $this->assertTrue($this->service()->update($id, $this->changed($id, ['name' => 'Nový názov'])));
    }
}
