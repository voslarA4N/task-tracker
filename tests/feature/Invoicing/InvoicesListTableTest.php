<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Invoicing\InvoiceFilter;
use App\Libraries\Invoicing\InvoiceQueries;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class InvoicesListTableTest extends AuthTestCase
{
    use InvoiceFixtures;
    use MasterFixtures;

    private int $entity;
    private int $clientA;
    private int $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        Services::injectMock('clock', new FrozenClock('2026-11-12T10:00:00+01:00'));
        $this->entity  = $this->createEntity('SK');
        $this->clientA = $this->createClient(['name' => 'Alfa Klient']);
        $this->clientB = $this->createClient(['name' => 'Beta Klient']);
        $user          = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function invoice(int $client, string $number, string $total, string $issue, ?int $entity = null): int
    {
        $alloc = $this->createAllocation($this->createTask($client), '8.00', '2026-10', ['rate' => '135.00', 'amount' => '1080.00']);

        return $this->insertIssuedInvoiceRaw($client, $entity ?? $this->entity, '2026-10', [$alloc], '%PDF-1.4 test', ['number' => $number, 'total' => $total, 'issue_date' => $issue]);
    }

    /**
     * Tri faktury: cisla 202610001 (500, 2026-10-01, A), 202610002 (100, 2026-10-03, B), 202610003 (900, 2026-10-02, A).
     */
    private function seed(): void
    {
        $this->invoice($this->clientA, '202610001', '500.00', '2026-10-01');
        $this->invoice($this->clientB, '202610002', '100.00', '2026-10-03');
        $this->invoice($this->clientA, '202610003', '900.00', '2026-10-02');
    }

    public function testTableAndCardsInOneFragment(): void
    {
        $this->seed();
        $html = $this->body($this->get('/invoices'));

        $this->assertStringContainsString('dt-only-lg', $html);
        $this->assertStringContainsString('dt-only-sm', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('app-main--wide', $html);
        $this->assertMatchesRegularExpression('/aria-sort="descending"[^>]*>\s*<a[^>]*>\s*Vystavená/u', $html);
    }

    public function testSortByTotalAscending(): void
    {
        $this->seed();
        $html  = $this->body($this->get('/invoices?sort=total&dir=asc'));
        $table = substr($html, (int) strpos($html, '<table'));

        $this->assertLessThan(strpos($table, '202610001'), strpos($table, '202610002'));
        $this->assertLessThan(strpos($table, '202610003'), strpos($table, '202610001'));
        $this->assertMatchesRegularExpression('/aria-sort="ascending"[^>]*>\s*<a[^>]*>\s*Suma/u', $html);
    }

    public function testEvilSortFallsBackToDefaultOrder(): void
    {
        $this->seed();
        $html  = $this->body($this->get('/invoices?sort=evil&dir=asc;drop'));
        $table = substr($html, (int) strpos($html, '<table'));

        $this->assertLessThan(strpos($table, '202610003'), strpos($table, '202610002'));
        $this->assertLessThan(strpos($table, '202610001'), strpos($table, '202610003'));
    }

    public function testFilterByClient(): void
    {
        $this->seed();
        $html = $this->body($this->get('/invoices?client=' . $this->clientB));

        $this->assertStringContainsString('202610002', $html);
        $this->assertStringNotContainsString('202610001', $html);
    }

    public function testFilterByNumberQuery(): void
    {
        $this->seed();
        $html = $this->body($this->get('/invoices?q=10003'));

        $this->assertStringContainsString('202610003', $html);
        $this->assertStringNotContainsString('202610002', $html);
    }

    public function testFilterByEntity(): void
    {
        $this->seed();
        $other = $this->createEntity('SK', ['name_internal' => 'Iny subjekt']);
        $this->invoice($this->clientA, '202610009', '50.00', '2026-10-04', $other);

        $html = $this->body($this->get('/invoices?entity=' . $other));

        $this->assertStringContainsString('202610009', $html);
        $this->assertStringNotContainsString('202610001', $html);
    }

    public function testFilterByDateRange(): void
    {
        $this->seed();
        $html = $this->body($this->get('/invoices?from=2026-10-02&to=2026-10-02'));

        $this->assertStringContainsString('202610003', $html);
        $this->assertStringNotContainsString('202610001', $html);
        $this->assertStringNotContainsString('202610002', $html);
    }

    public function testHtmxFragment(): void
    {
        $this->seed();
        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/invoices?sort=total'));

        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringContainsString('id="invoice-list"', $html);
        $this->assertStringContainsString('<table', $html);
    }

    public function testUnlimitedListAndLastPaidOn(): void
    {
        $this->seed();
        $queries = new InvoiceQueries();

        $limited = $queries->list(2, null, '2026-11-12');
        $this->assertTrue($limited['truncated']);
        $this->assertCount(2, $limited['rows']);

        $all = $queries->list(null, null, '2026-11-12', InvoiceFilter::fromQuery([]));
        $this->assertFalse($all['truncated']);
        $this->assertCount(3, $all['rows']);
        $this->assertNull($all['rows'][0]['last_paid_on']);
        $this->assertSame($this->entity, (int) $all['rows'][0]['entity_id']);
        $this->assertArrayHasKey('turnover_date', $all['rows'][0]);

        $id = (int) $all['rows'][0]['id'];
        $this->owner()->query('INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, :d:, :a:, now())', ['i' => $id, 'd' => '2026-11-01', 'a' => '10.00']);
        $again = $queries->list(null, null, '2026-11-12');
        $paid  = array_values(array_filter($again['rows'], static fn (array $r): bool => (int) $r['id'] === $id));
        $this->assertSame('2026-11-01', $paid[0]['last_paid_on']);
    }
}
