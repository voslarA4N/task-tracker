<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class IssueIdempotencyTest extends PgTestCase
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

    private function invoiceCount(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM invoices')->getRow()->c;
    }

    private function rejection(callable $fn): IssueRejected
    {
        try {
            $fn();
        } catch (IssueRejected $e) {
            return $e;
        }

        $this->fail('Expected IssueRejected');
    }

    public function testSameRequestKeyReturnsFirstInvoice(): void
    {
        $s     = $this->issueSetup();
        $cmd   = $this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']);
        $first = $this->issuer()->issue($cmd);

        $e = $this->rejection(fn () => $this->issuer()->issue($cmd));

        $this->assertSame('already_issued', $e->reason);
        $this->assertSame($first->id, $e->invoiceId);
        $this->assertSame($first->number, $e->invoiceNumber);
        $this->assertSame(1, $this->invoiceCount());
    }

    public function testNewRequestKeyForLockedPartsMentionsInvoiceNumber(): void
    {
        $s = $this->issueSetup();
        $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        $e = $this->rejection(fn () => $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'])));

        $this->assertSame('parts_changed', $e->reason);
        $this->assertSame('202610001', $e->invoiceNumber);
        $this->assertSame(1, $this->invoiceCount());
    }

    public function testChangedHoursAfterPreviewAreRejectedAsStale(): void
    {
        $s   = $this->issueSetup();
        $cmd = $this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']);
        $this->owner()->query("UPDATE billing_allocations SET hours = '41.00', amount = '1107.00' WHERE id = :id:", ['id' => $s['parts'][0]]);

        $e = $this->rejection(fn () => $this->issuer()->issue($cmd));

        $this->assertSame('preview_stale', $e->reason);
        $this->assertSame(0, $this->invoiceCount());
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_counters')->getRow()->c);
    }

    public function testPartMovedToAnotherMonthIsRejectedWithoutInvoiceId(): void
    {
        $s   = $this->issueSetup();
        $cmd = $this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']);
        $this->owner()->query("UPDATE billing_allocations SET billing_month = '2026-11' WHERE id = :id:", ['id' => $s['parts'][0]]);

        $e = $this->rejection(fn () => $this->issuer()->issue($cmd));

        $this->assertSame('parts_changed', $e->reason);
        $this->assertNull($e->invoiceId);
        $this->assertSame(0, $this->invoiceCount());
    }
}
