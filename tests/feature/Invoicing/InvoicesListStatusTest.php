<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

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
final class InvoicesListStatusTest extends AuthTestCase
{
    use InvoiceFixtures;
    use MasterFixtures;

    private int $client;
    private int $entity;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        $this->entity = $this->createEntity('SK');
        $this->client = $this->createClient();
        Services::injectMock('clock', new FrozenClock('2026-11-12T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
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

    private function invoice(string $due, string $number): int
    {
        $this->seq++;
        $alloc = $this->createAllocation($this->createTask($this->client), '8.00', '2026-10', ['rate' => '135.00', 'amount' => '1080.00']);

        return $this->insertIssuedInvoiceRaw($this->client, $this->entity, '2026-10', [$alloc], '%PDF-1.4 test', ['due_date' => $due, 'number' => $number]);
    }

    private function pay(int $id, string $amount): void
    {
        $this->owner()->query('INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, :d:, :a:, now())', ['i' => $id, 'd' => '2026-11-01', 'a' => $amount]);
    }

    /**
     * @return array{issued: int, sent: int, partial: int, paid: int, overdue: int}
     */
    private function seed(): array
    {
        $this->login();
        $issued  = $this->invoice('2026-12-01', '202610001');
        $sent    = $this->invoice('2026-12-01', '202610002');
        $partial = $this->invoice('2026-12-01', '202610003');
        $paid    = $this->invoice('2026-12-01', '202610004');
        $overdue = $this->invoice('2026-11-01', '202610005');

        $this->owner()->query("INSERT INTO invoice_sends (invoice_id, kind, sent_at, transport, counts_as_sent, created_at) VALUES (:i:, 'invoice', now(), 'smtp', true, now())", ['i' => $sent]);
        $this->pay($partial, '80.00');
        $this->pay($paid, '1080.00');

        return ['issued' => $issued, 'sent' => $sent, 'partial' => $partial, 'paid' => $paid, 'overdue' => $overdue];
    }

    private function login(): void
    {
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    public function testBadgesAndBalance(): void
    {
        $this->seed();
        $html = $this->body($this->get('/invoices'));

        $this->assertStringContainsString('st-badges', $html);
        $this->assertStringContainsString('Odoslaná', $html);
        $this->assertStringContainsString('Čiastočne uhradená', $html);
        $this->assertStringContainsString('Po splatnosti · 11 dní', $html);
        $this->assertMatchesRegularExpression('/zostatok\s+1&nbsp;000,00|zostatok\s+1\xC2\xA0000,00|zostatok\s+1.000,00/u', $html);
        $this->assertStringContainsString('name="status"', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle=|onclick/i', $html);
    }

    public function testStatusFilters(): void
    {
        $this->seed();

        $paid = $this->body($this->get('/invoices?status=paid'));
        $this->assertStringContainsString('202610004', $paid);
        $this->assertStringNotContainsString('202610001', $paid);

        $overdue = $this->body($this->get('/invoices?status=overdue'));
        $this->assertStringContainsString('202610005', $overdue);
        $this->assertStringNotContainsString('202610004', $overdue);
        $this->assertStringNotContainsString('202610002', $overdue);

        $unknown = $this->body($this->get('/invoices?status=xyz'));
        $this->assertStringContainsString('202610001', $unknown);
        $this->assertStringContainsString('202610004', $unknown);
        $this->assertStringContainsString('202610005', $unknown);
    }

    public function testEmptyFilteredResult(): void
    {
        $this->login();
        $this->invoice('2026-12-01', '202610001');

        $html = $this->body($this->get('/invoices?status=paid'));

        $this->assertStringContainsString('Žiadne faktúry v tomto stave.', $html);
    }
}
