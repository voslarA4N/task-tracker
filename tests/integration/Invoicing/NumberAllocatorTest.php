<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\DocumentIntegrityError;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Invoicing\NumberAllocator;
use App\Libraries\Invoicing\RenderedPdf;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Transaction;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class NumberAllocatorTest extends PgTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
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

    /**
     * Pridelenie v transakcii so zamkom radu; potom vlozi faktúru s tym cislom, aby logika "first" videla doklady.
     *
     * @param array<string, mixed> $s
     */
    private function allocate(array $s, string $date, bool $persist = true): string
    {
        $number = Transaction::run($this->rw(), function () use ($s, $date): string {
            $series = $this->rw()->query('SELECT * FROM invoice_series WHERE id = :id: FOR UPDATE', ['id' => $s['series']])->getRowArray();

            return (new NumberAllocator($this->rw()))->next($series, $date)->number;
        });

        if ($persist) {
            $task  = $this->createTask($s['client']);
            $alloc = $this->createAllocation($task, '1.00', '2026-10');
            $this->insertIssuedInvoiceRaw($s['client'], $s['entity'], '2026-10', [$alloc], '%PDF-1.4 x', ['number' => $number]);
        }

        return $number;
    }

    public function testMonthlyFormatNumbersWithoutGapsAndResetsPerMonth(): void
    {
        $s = $this->issueSetup();

        $this->assertSame('202610001', $this->allocate($s, '2026-10-27'));
        $this->assertSame('202610002', $this->allocate($s, '2026-10-28'));
        $this->assertSame('202611001', $this->allocate($s, '2026-11-02'));
    }

    public function testInitialNumberAppliesOnlyToFirstDocumentOfSeries(): void
    {
        $s = $this->issueSetup(['initial' => 5]);

        $this->assertSame('202610005', $this->allocate($s, '2026-10-27'));
        $this->assertSame('202610006', $this->allocate($s, '2026-10-28'));
        $this->assertSame('202611001', $this->allocate($s, '2026-11-02'));
    }

    public function testYearlyFormatResetsEachYear(): void
    {
        $s = $this->issueSetup(['format' => 'RRRR###']);

        $this->assertSame('2026001', $this->allocate($s, '2026-10-27'));
        $this->assertSame('2026002', $this->allocate($s, '2026-12-31'));
        $this->assertSame('2027001', $this->allocate($s, '2027-01-02'));

        $keys = array_column($this->owner()->query('SELECT period_key FROM invoice_counters WHERE series_id = :s: ORDER BY period_key', ['s' => $s['series']])->getResultArray(), 'period_key');
        $this->assertSame(['2026', '2027'], $keys);
    }

    public function testRollbackReturnsTheCounter(): void
    {
        $s = $this->issueSetup();

        try {
            Transaction::run($this->rw(), function () use ($s): void {
                $series = $this->rw()->query('SELECT * FROM invoice_series WHERE id = :id: FOR UPDATE', ['id' => $s['series']])->getRowArray();
                (new NumberAllocator($this->rw()))->next($series, '2026-10-27');

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_counters')->getRow()->c);
        $this->assertSame('202610001', $this->allocate($s, '2026-10-27'));
    }

    public function testSequenceOverflowIsRejectedAndCounterStays(): void
    {
        $s = $this->issueSetup(['format' => 'RRRRMM#']);

        for ($i = 1; $i <= 9; $i++) {
            $this->assertSame('202610' . $i, $this->allocate($s, '2026-10-27', false));
        }

        try {
            $this->allocate($s, '2026-10-27', false);
            $this->fail('Expected IssueRejected');
        } catch (IssueRejected $e) {
            $this->assertSame('sequence_overflow', $e->reason);
        }

        $this->assertSame(9, (int) $this->owner()->query('SELECT last_value FROM invoice_counters WHERE series_id = :s:', ['s' => $s['series']])->getRow()->last_value);
    }

    public function testDocumentStoreRoundTripsBinaryBytes(): void
    {
        $s       = $this->issueSetup();
        $invoice = $this->insertExternalInvoice($s['client'], '2026-10', [$s['parts'][0]]);
        $bytes   = random_bytes(4096) . "\0\0end";
        $pdf     = RenderedPdf::fromBytes($bytes, 'inv-1', 'test');
        $store   = new DocumentStore($this->rw());

        $store->store($invoice, 'invoice', $pdf);
        $read = $store->read($invoice);

        $this->assertNotNull($read);
        $this->assertSame($bytes, $read->bytes);
        $this->assertSame($pdf->sha256, $read->sha256);
        $this->assertSame(strlen($bytes), $read->byteSize);

        $meta = $store->meta($invoice);
        $this->assertNotNull($meta);
        $this->assertArrayNotHasKey('content', $meta);
        $this->assertSame($pdf->sha256, $meta['sha256']);
        $this->assertNull($store->read($invoice, 'report'));
    }

    public function testVerifyDetectsTamperedBytes(): void
    {
        DocumentStore::verify('abc', hash('sha256', 'abc'));

        $this->expectException(DocumentIntegrityError::class);
        DocumentStore::verify('abc', hash('sha256', 'iné'));
    }
}
