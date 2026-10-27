<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\ExternalInvoiceLocker;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Work\AllocationLocked;
use App\Libraries\Work\AllocationService;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use DomainException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class LockHistoricalTest extends PgTestCase
{
    use MasterFixtures;
    use StreamFilterTrait;

    private int $client = 0;

    /**
     * @var list<int>
     */
    private array $allocs = [];

    private int $manualAlloc = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
        $this->client = $this->createClient();

        foreach ([1, 2, 3] as $i) {
            $task           = $this->createTask($this->client, ['title' => 'Import ' . $i, 'import_key' => str_repeat((string) $i, 64)]);
            $this->allocs[] = $this->createAllocation($task, '8.00', '2026-09');
        }
        $manual            = $this->createTask($this->client, ['title' => 'Ručná']);
        $this->manualAlloc = $this->createAllocation($manual, '5.00', '2026-09');
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function run2(string $args): string
    {
        $this->resetStreamFilterBuffer();
        command('invoices:lock-historical ' . $args);

        return $this->getStreamFilterBuffer();
    }

    private function rowCount(string $where): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM ' . $where)->getRow()->c;
    }

    private function base(): string
    {
        return '--client ' . $this->client . ' --month 2026-09';
    }

    public function testPlanExcludesManualParts(): void
    {
        $plan = (new ExternalInvoiceLocker($this->rw()))->plan($this->client, '2026-09');

        $this->assertSame(3, $plan['count']);
        $this->assertSame('24.00', $plan['hours']);
        $this->assertSame('648.00', $plan['amount']);
        $this->assertSame(['EUR'], $plan['currencies']);
        $this->assertNotContains($this->manualAlloc, array_column($plan['parts'], 'id'));
    }

    public function testDryRunWritesNothing(): void
    {
        $out = $this->run2($this->base());

        $this->assertStringContainsString('Počet častí: 3', $out);
        $this->assertStringContainsString('648.00', $out);
        $this->assertStringContainsString('Suchý beh', $out);
        $this->assertSame(0, $this->rowCount('invoices'));
        $this->assertSame(0, $this->rowCount('billing_allocations WHERE invoice_id IS NOT NULL'));
    }

    public function testCommitRequiresExpectations(): void
    {
        $this->run2($this->base() . ' --commit');

        $this->assertSame(0, $this->rowCount('invoices'));
    }

    public function testMismatchWritesNothing(): void
    {
        $out = $this->run2($this->base() . ' --expect-parts 3 --expect-hours 24 --expect-amount 640 --commit');

        $this->assertStringContainsString('expectation_mismatch', $out);
        $this->assertSame(0, $this->rowCount('invoices'));
        $this->assertSame(0, $this->rowCount('billing_allocations WHERE invoice_id IS NOT NULL'));
    }

    public function testCommitLocksPartsOnExternalInvoiceAndIsIdempotent(): void
    {
        $counters = $this->rowCount('invoice_counters');

        $this->run2($this->base() . ' --expect-parts 3 --expect-hours 24 --expect-amount 648 --ref "FA 2026/09 (tretia osoba)" --commit');

        $inv = $this->owner()->query('SELECT * FROM invoices')->getRow();
        $this->assertSame('external', $inv->origin);
        $this->assertNull($inv->number);
        $this->assertNull($inv->series_id);
        $this->assertFalse(in_array($inv->counts_toward_limit, [true, 't', '1', 1], true));
        $this->assertSame('648.00', (string) $inv->total);
        $this->assertSame(3, (int) $inv->line_count);
        $this->assertSame('FA 2026/09 (tretia osoba)', $inv->external_ref);
        $this->assertSame(3, $this->rowCount('billing_allocations WHERE invoice_id = ' . (int) $inv->id));
        $this->assertSame(0, $this->rowCount('billing_allocations WHERE id = ' . $this->manualAlloc . ' AND invoice_id IS NOT NULL'));
        $this->assertSame(1, $this->rowCount("audit_log WHERE entity_type = 'invoice' AND action = 'external_lock'"));
        $this->assertSame($counters, $this->rowCount('invoice_counters'));
        $this->assertSame(0, $this->rowCount('invoice_documents'));

        $out = $this->run2($this->base() . ' --expect-parts 3 --expect-hours 24 --expect-amount 648 --ref "FA 2026/09 (tretia osoba)" --commit');
        $this->assertStringContainsString('nothing_to_lock', $out);
        $this->assertSame(1, $this->rowCount('invoices'));
    }

    public function testLockedImportedPartIsImmutable(): void
    {
        (new ExternalInvoiceLocker($this->rw(), new AuditLogger($this->rw())))->lock($this->client, '2026-09', null, 3, '24.00', '648.00');

        $this->expectException(AllocationLocked::class);
        (new AllocationService($this->rw(), new AuditLogger($this->rw())))->update($this->allocs[0], ['hours' => '9.00', 'billing_month' => '2026-09']);
    }

    public function testNothingToLockThrows(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('nothing_to_lock');
        (new ExternalInvoiceLocker($this->rw()))->lock($this->client, '2026-08', null, 1, '1', '1');
    }

    public function testInvalidRefRejected(): void
    {
        $this->run2($this->base() . ' --expect-parts 3 --expect-hours 24 --expect-amount 648 --ref ' . escapeshellarg(str_repeat('x', 301)) . ' --commit');
        $this->assertSame(0, $this->rowCount('invoices'));

        $this->run2($this->base() . ' --expect-parts 3 --expect-hours 24 --expect-amount 648 --ref ' . escapeshellarg("a\x07b") . ' --commit');
        $this->assertSame(0, $this->rowCount('invoices'));
    }
}
