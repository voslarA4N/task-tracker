<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class AllocationLockTest extends PgTestCase
{
    use InvoiceFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function assertDbError(string $regex, callable $fn): void
    {
        try {
            $fn();
        } catch (DatabaseException $e) {
            $this->assertMatchesRegularExpression($regex, $e->getMessage());

            return;
        }

        $this->fail('Expected DatabaseException matching ' . $regex);
    }

    private function lock(int $invoice, string $ids): int
    {
        return (int) $this->rw()->query('SELECT lock_allocations(:inv:, CAST(:ids: AS bigint[])) AS n', ['inv' => $invoice, 'ids' => $ids])->getRow()->n;
    }

    private function lockedId(int $alloc): ?string
    {
        $v = $this->owner()->query('SELECT invoice_id FROM billing_allocations WHERE id = ?', [$alloc])->getRow()->invoice_id;

        return $v === null ? null : (string) $v;
    }

    public function testLockAllocationsHappyPath(): void
    {
        $entity = $this->createEntity('SK');
        $client = $this->createClient();
        $t1     = $this->createTask($client);
        $a      = $this->createAllocation($t1, '8.00', '2026-10');
        $b      = $this->createAllocation($this->createTask($client), '2.00', '2026-10');
        $inv    = $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$a, $b]);

        $this->assertSame(2, $this->lock($inv, '{' . $a . ',' . $b . '}'));
        $this->assertSame((string) $inv, $this->lockedId($a));
        $this->assertSame((string) $inv, $this->lockedId($b));
        $this->assertSame(0, $this->lock($inv, '{' . $a . ',' . $b . '}'));
    }

    public function testLockRejectsMismatchingParts(): void
    {
        $entity = $this->createEntity('SK');
        $client = $this->createClient();
        $other  = $this->createClient(['name' => 'Iny', 'identifier_value' => '87654321']);
        $otherC = $this->createClient(['name' => 'PL', 'currency' => 'PLN', 'identifier_value' => '11111111']);

        $good      = $this->createAllocation($this->createTask($client), '8.00', '2026-10');
        $diffClnt  = $this->createAllocation($this->createTask($other), '1.00', '2026-10');
        $diffMonth = $this->createAllocation($this->createTask($client), '1.00', '2026-09');
        $diffCcy   = $this->createAllocation($this->createTask($otherC), '1.00', '2026-10');
        $noLine    = $this->createAllocation($this->createTask($client), '1.00', '2026-10');
        $inv       = $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$good]);

        $ids = implode(',', [$diffClnt, $diffMonth, $diffCcy, $noLine]);
        $this->assertSame(0, $this->lock($inv, '{' . $ids . '}'));

        foreach ([$diffClnt, $diffMonth, $diffCcy, $noLine] as $id) {
            $this->assertNull($this->lockedId($id));
        }
    }

    public function testRwCannotSetInvoiceIdDirectly(): void
    {
        $entity = $this->createEntity('SK');
        $client = $this->createClient();
        $a      = $this->createAllocation($this->createTask($client), '8.00', '2026-10');
        $inv    = $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$a]);

        $this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE billing_allocations SET invoice_id = :inv:', ['inv' => $inv]));
    }

    public function testLockedPartIsImmutableEvenForOwner(): void
    {
        $client = $this->createClient();
        $a      = $this->createAllocation($this->createTask($client), '8.00', '2026-10');
        $this->lockAllocation($a);

        $this->assertDbError('/is locked/', fn () => $this->owner()->query('UPDATE billing_allocations SET hours = 1 WHERE id = ?', [$a]));
        $this->assertDbError('/is locked/', fn () => $this->owner()->query('DELETE FROM billing_allocations WHERE id = ?', [$a]));
    }

    public function testOwnerLockCannotChangeOtherColumns(): void
    {
        $client = $this->createClient();
        $a      = $this->createAllocation($this->createTask($client), '8.00', '2026-10');
        $this->insertExternalInvoiceNoLock($client, $a);
        $inv = (int) $this->owner()->query('SELECT max(id) AS id FROM invoices')->getRow()->id;

        $this->assertDbError('/lock must not change other columns/', fn () => $this->owner()->query('UPDATE billing_allocations SET invoice_id = :inv:, hours = 1 WHERE id = :id:', ['inv' => $inv, 'id' => $a]));
    }

    public function testInvoiceIdForeignKey(): void
    {
        $client = $this->createClient();
        $a      = $this->createAllocation($this->createTask($client), '8.00', '2026-10');

        $this->assertDbError('/foreign key/i', fn () => $this->owner()->query('UPDATE billing_allocations SET invoice_id = 999999 WHERE id = ?', [$a]));
    }

    public function testLockFunctionNotPublic(): void
    {
        $row = $this->owner()->query("SELECT has_function_privilege('public', 'lock_allocations(bigint, bigint[])', 'EXECUTE') AS p, has_function_privilege('app_rw', 'lock_allocations(bigint, bigint[])', 'EXECUTE') AS rw")->getRow();
        $this->assertFalse(in_array($row->p, [true, 't', '1', 1], true));
        $this->assertTrue(in_array($row->rw, [true, 't', '1', 1], true));
    }

    public function testStatusView(): void
    {
        $entity = $this->createEntity('SK');
        $client = $this->createClient();
        $a      = $this->createAllocation($this->createTask($client), '8.00', '2026-10');
        $b      = $this->createAllocation($this->createTask($client), '2.00', '2026-09');
        $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$a]);
        $this->lockAllocation($b);

        $rows = $this->rw()->query('SELECT origin, status FROM invoice_status_v ORDER BY id')->getResultArray();
        $this->assertSame([['origin' => 'issued', 'status' => 'issued'], ['origin' => 'external', 'status' => 'external']], $rows);

        $cols = array_column($this->owner()->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'invoice_status_v'")->getResultArray(), 'column_name');
        $this->assertNotContains('snapshot', $cols);
    }

    /**
     * Externa faktura s riadkom, ale bez nastavenia invoice_id na casti (na test owner zamku).
     */
    private function insertExternalInvoiceNoLock(int $client, int $alloc): void
    {
        $a = $this->owner()->query('SELECT a.*, t.title FROM billing_allocations a JOIN tasks t ON t.id = a.task_id WHERE a.id = ?', [$alloc])->getRow();
        Transaction::run($this->owner(), function () use ($client, $a): void {
            $db = $this->owner();
            $db->query(
                "INSERT INTO invoices (origin, client_id, billing_month, currency, total, counts_toward_limit, line_count, snapshot, snapshot_schema, created_at)
                 VALUES ('external', :c:, :m:, :ccy:, :t:, false, 1, '{}', 1, now())",
                ['c' => $client, 'm' => $a->billing_month, 'ccy' => trim((string) $a->currency), 't' => $a->amount],
            );
            $id = (int) $db->query('SELECT max(id) AS id FROM invoices')->getRow()->id;
            $db->query(
                'INSERT INTO invoice_lines (invoice_id, position, source_allocation_id, source_task_id, title, hours, rate, amount) VALUES (:i:, 1, :a:, :t:, :title:, :h:, :r:, :amt:)',
                ['i' => $id, 'a' => $a->id, 't' => $a->task_id, 'title' => $a->title, 'h' => $a->hours, 'r' => $a->rate, 'amt' => $a->amount],
            );
            $db->query('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }
}
