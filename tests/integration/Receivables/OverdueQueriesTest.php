<?php

declare(strict_types=1);

namespace Tests\Integration\Receivables;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Receivables\InvoiceStatusQueries;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class OverdueQueriesTest extends PgTestCase
{
    use InvoiceFixtures;
    use MasterFixtures;

    private int $client;
    private int $entity;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
        $this->entity = $this->createEntity('SK');
        $this->client = $this->createClient();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function invoice(string $due, ?int $client = null, string $rate = '135.00', string $amount = '1080.00'): int
    {
        $client ??= $this->client;
        ++$this->seq;
        $alloc = $this->createAllocation($this->createTask($client), '8.00', '2026-10', ['rate' => $rate, 'amount' => $amount]);

        return $this->insertIssuedInvoiceRaw($client, $this->entity, '2026-10', [$alloc], '%PDF-1.4 test', ['due_date' => $due, 'number' => '2026100' . sprintf('%02d', $this->seq)]);
    }

    private function pay(int $invoice, string $amount): void
    {
        $this->owner()->query('INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, :d:, :a:, now())', ['i' => $invoice, 'd' => '2026-10-28', 'a' => $amount]);
    }

    private function q(): InvoiceStatusQueries
    {
        return new InvoiceStatusQueries($this->rw());
    }

    public function testDueDayIsNotOverdueButDayBeforeIs(): void
    {
        $day    = $this->invoice('2026-11-10');
        $before = $this->invoice('2026-11-09');
        $r      = $this->q()->overdue('2026-11-10', null, 10);

        $this->assertSame([$before], array_column($r['rows'], 'id'));
        $this->assertSame(1, $r['rows'][0]['days_overdue']);
        $this->assertSame('1080.00', $r['rows'][0]['balance']);
        $this->assertSame(1, $r['total']);
        $this->assertNotContains($day, array_column($r['rows'], 'id'));
    }

    public function testPaidExcludedPartialIncludedWithBalance(): void
    {
        $paid    = $this->invoice('2026-11-01');
        $partial = $this->invoice('2026-11-02');
        $this->pay($paid, '1080.00');
        $this->pay($partial, '80.00');

        $r = $this->q()->overdue('2026-11-10', null, 10);

        $this->assertSame([$partial], array_column($r['rows'], 'id'));
        $this->assertSame('1000.00', $r['rows'][0]['balance']);
        $this->assertSame('partially_paid', $r['rows'][0]['status']);
    }

    public function testExternalAndZeroTotalNeverOverdue(): void
    {
        $task = $this->createTask($this->client);
        $this->lockAllocation($this->createAllocation($task, '16.00', '2026-09'));
        $this->invoice('2026-11-01', null, '0.00', '0.00');

        $r = $this->q()->overdue('2026-12-31', null, 10);

        $this->assertSame([], $r['rows']);
        $this->assertSame(0, $r['total']);
    }

    public function testOrderLimitAndTotal(): void
    {
        $c = $this->invoice('2026-11-03');
        $a = $this->invoice('2026-11-01');
        $b = $this->invoice('2026-11-02');

        $r = $this->q()->overdue('2026-11-10', null, 2);

        $this->assertSame([$a, $b], array_column($r['rows'], 'id'));
        $this->assertSame(3, $r['total']);
        $this->assertContains($c, array_column($this->q()->overdue('2026-11-10', null, 10)['rows'], 'id'));
    }

    public function testClientFilter(): void
    {
        $other = $this->createClient(['name' => 'Iny klient']);
        $mine  = $this->invoice('2026-11-01');
        $this->invoice('2026-11-01', $other);

        $r = $this->q()->overdue('2026-11-10', $this->client, 10);

        $this->assertSame([$mine], array_column($r['rows'], 'id'));
        $this->assertSame(1, $r['total']);
        $this->assertSame(1, $this->q()->countOverdue('2026-11-10', $this->client));
        $this->assertSame(2, $this->q()->countOverdue('2026-11-10', null));
    }

    public function testDayBoundaryFollowsBratislavaClock(): void
    {
        $inv = $this->invoice('2026-11-10');

        Services::injectMock('clock', new FrozenClock('2026-11-10T23:30:00+01:00'));
        $this->assertSame('2026-11-10', service('clock')->today());
        $this->assertSame([], $this->q()->overdue(service('clock')->today(), null, 10)['rows']);

        Services::injectMock('clock', new FrozenClock('2026-11-11T00:30:00+01:00'));
        $this->assertSame('2026-11-11', service('clock')->today());
        $r = $this->q()->overdue(service('clock')->today(), null, 10);
        $this->assertSame([$inv], array_column($r['rows'], 'id'));
        $this->assertSame(1, $r['rows'][0]['days_overdue']);
    }
}
