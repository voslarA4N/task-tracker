<?php

declare(strict_types=1);

namespace Tests\Integration\Work;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Work\LastClient;
use App\Libraries\Work\TaskRejected;
use App\Libraries\Work\TaskService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class TaskServiceTest extends PgTestCase
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

    private function service(): TaskService
    {
        return new TaskService($this->rw(), new AuditLogger($this->rw()));
    }

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private function values(int $clientId, array $over = []): array
    {
        return $over + [
            'client_id' => $clientId,
            'url'       => null,
            'title'     => 'Oprava exportu',
            'hours'     => '40.00',
            'rate'      => '27.00',
            'note'      => null,
        ];
    }

    private function audits(string $type, string $action): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM audit_log WHERE entity_type = ? AND action = ?', [$type, $action])->getRow()->c;
    }

    public function testCreateStoresInProgressTaskAndAudits(): void
    {
        $client = $this->createClient();
        $id     = $this->service()->create($this->values($client));
        $task   = $this->service()->find($id);

        $this->assertNotNull($task);
        $this->assertSame('in_progress', $task['status']);
        $this->assertNull($task['done_at']);
        $this->assertSame('40.00', $task['hours']);
        $this->assertSame('27.00', $task['rate']);
        $this->assertSame($client, $task['client_id']);
        $this->assertSame(1, $this->audits('task', 'create'));
    }

    public function testDbRejectsQuotedAmountOverflow(): void
    {
        // CR-01: posledna linia v DB (migracia AddTasksQuotedAmountCheck).
        $client = $this->createClient();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/tasks_quoted_amount_fits/');
        $this->service()->create($this->values($client, ['hours' => '10000.00', 'rate' => '1000000.00']));
    }

    public function testCreateRejectsArchivedClient(): void
    {
        $client = $this->createClient();
        $this->owner()->query('UPDATE clients SET archived_at = now() WHERE id = ?', [$client]);

        try {
            $this->service()->create($this->values($client));
            $this->fail('Expected TaskRejected');
        } catch (TaskRejected $e) {
            $this->assertSame('client_unavailable', $e->reason);
        }

        $this->assertSame(0, $this->audits('task', 'create'));
    }

    public function testDigitsUrlExpandedWithClientTemplate(): void
    {
        $client = $this->createClient(['portal_url_template' => 'https://taskman.example/?todo={id}']);
        $id     = $this->service()->create($this->values($client, ['url' => '81252']));

        $this->assertSame('https://taskman.example/?todo=81252', $this->service()->find($id)['url']);
    }

    public function testUpdateExpandsDigitsUrlWithClientTemplate(): void
    {
        // WR-05: uprava rozvinie cislo tiketu rovnako ako vytvorenie (aj po zmene klienta na klienta so sablonou).
        $client = $this->createClient(['portal_url_template' => 'https://taskman.example/?todo={id}']);
        $id     = $this->service()->create($this->values($client));

        $this->assertTrue($this->service()->update($id, $this->values($client, ['url' => '81252'])));
        $this->assertSame('https://taskman.example/?todo=81252', $this->service()->find($id)['url']);

        // Rovnaky tiket znova: po rozvinuti sa nic nemeni.
        $this->assertFalse($this->service()->update($id, $this->values($client, ['url' => '81252'])));

        $other = $this->createClient(['name' => 'Iny', 'identifier_value' => '777', 'portal_url_template' => 'https://other.example/t/{id}']);
        $this->service()->update($id, $this->values($other, ['url' => '5']));
        $this->assertSame('https://other.example/t/5', $this->service()->find($id)['url']);
    }

    public function testUpdateAuditsOnlyChangedFieldsAndKeepsAllocationRate(): void
    {
        $client = $this->createClient();
        $id     = $this->service()->create($this->values($client));
        $alloc  = $this->createAllocation($id, '10.00', '2026-10');

        $this->assertTrue($this->service()->update($id, $this->values($client, ['title' => 'Nový názov', 'rate' => '30.00'])));

        $task = $this->service()->find($id);
        $this->assertSame('Nový názov', $task['title']);
        $this->assertSame('30.00', $task['rate']);
        $this->assertSame('27.00', (string) $this->owner()->query('SELECT rate FROM billing_allocations WHERE id = ?', [$alloc])->getRow()->rate);

        $row     = $this->owner()->query("SELECT before_data, after_data FROM audit_log WHERE entity_type = 'task' AND action = 'update'")->getRow();
        $changed = array_keys(json_decode((string) $row->after_data, true));
        sort($changed);
        $this->assertSame(['rate', 'title'], $changed);
    }

    public function testUpdateChangesClientWithoutAllocations(): void
    {
        $a  = $this->createClient();
        $b  = $this->createClient(['name' => 'Iný']);
        $id = $this->service()->create($this->values($a));

        $this->service()->update($id, $this->values($b));

        $this->assertSame($b, $this->service()->find($id)['client_id']);
    }

    public function testUpdateRejectsClientChangeWithAllocation(): void
    {
        $a  = $this->createClient();
        $b  = $this->createClient(['name' => 'Iný']);
        $id = $this->service()->create($this->values($a));
        $this->createAllocation($id, '10.00', '2026-10');

        try {
            $this->service()->update($id, $this->values($b));
            $this->fail('Expected TaskRejected');
        } catch (TaskRejected $e) {
            $this->assertSame('client_locked', $e->reason);
        }

        $this->assertSame($a, $this->service()->find($id)['client_id']);
    }

    public function testUpdateRejectsArchivedTargetClient(): void
    {
        $a = $this->createClient();
        $b = $this->createClient(['name' => 'Iný']);
        $this->owner()->query('UPDATE clients SET archived_at = now() WHERE id = ?', [$b]);
        $id = $this->service()->create($this->values($a));

        $this->expectException(TaskRejected::class);
        $this->service()->update($id, $this->values($b));
    }

    public function testLastClientFollowsLatestTask(): void
    {
        $this->assertNull((new LastClient($this->rw()))->resolve());

        $a = $this->createClient(['name' => 'Alfa']);
        $b = $this->createClient(['name' => 'Beta']);
        $this->createTask($a);
        $this->createTask($b);

        $this->assertSame($b, (new LastClient($this->rw()))->resolve());

        $this->owner()->query('UPDATE clients SET archived_at = now() WHERE id = ?', [$b]);
        $this->assertSame($a, (new LastClient($this->rw()))->resolve());
    }
}
