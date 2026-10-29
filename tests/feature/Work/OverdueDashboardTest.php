<?php

declare(strict_types=1);

namespace Tests\Feature\Work;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class OverdueDashboardTest extends AuthTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
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
        $this->useClock('2026-11-12T10:00:00+01:00');
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function useClock(string $at): void
    {
        Services::injectMock('clock', new FrozenClock($at));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    private function login(): void
    {
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function invoice(string $due = '2026-11-11', ?int $client = null): int
    {
        $client ??= $this->client;
        $this->seq++;
        $alloc = $this->createAllocation($this->createTask($client), '8.00', '2026-10', ['rate' => '135.00', 'amount' => '1080.00']);

        return $this->insertIssuedInvoiceRaw($client, $this->entity, '2026-10', [$alloc], '%PDF-1.4 test', ['due_date' => $due, 'number' => '2026100' . sprintf('%02d', $this->seq)]);
    }

    public function testSectionHiddenWithoutOverdue(): void
    {
        $this->login();
        $this->invoice('2026-11-12');

        $html = $this->body($this->get('/?client=all'));

        $this->assertStringNotContainsString('Po splatnosti', $html);
        $this->assertStringNotContainsString('ov-row', $html);
    }

    public function testOverdueRowWithPaymentAction(): void
    {
        $this->login();
        $id = $this->invoice();

        $html = $this->body($this->get('/?client=all'));

        $this->assertStringContainsString('Po splatnosti', $html);
        $this->assertStringContainsString('2026100', $html);
        $this->assertStringContainsString('1 deň po splatnosti', $html);
        $this->assertStringContainsString('hx-get="/invoices/' . $id . '/payments?return_to=/"', $html);
        $this->assertStringContainsString('id="sheet"', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle=|onclick/i', $html);
    }

    public function testClientFilterHidesOtherClient(): void
    {
        $this->login();
        $this->invoice();
        $other = $this->createClient(['name' => 'Druhy klient']);

        $html = $this->body($this->get('/?client=' . $other));

        $this->assertStringNotContainsString('ov-row', $html);
    }

    public function testFullPaymentRemovesRow(): void
    {
        $this->login();
        $id = $this->invoice();
        $this->assertStringContainsString('ov-row', $this->body($this->get('/?client=all')));

        $this->withSession();
        $this->withHeaders(['HX-Request' => 'true'])->call('post', '/invoices/' . $id . '/payments', [
            'paid_on'    => '2026-11-12', 'amount' => '', 'note' => '', 'request_key' => $this->uuid4(), 'return_to' => '/',
            csrf_token() => csrf_hash(),
        ]);

        $html = $this->body($this->get('/?client=all'));
        $this->assertStringNotContainsString('ov-row', $html);
        $this->assertStringNotContainsString('Po splatnosti', $html);
    }

    public function testLimitTenWithShowAllLink(): void
    {
        $this->login();

        for ($i = 0; $i < 11; $i++) {
            $this->invoice();
        }

        $html = $this->body($this->get('/?client=all'));

        $this->assertSame(10, substr_count($html, 'class="ov-row"'));
        $this->assertStringContainsString('href="/invoices?status=overdue"', $html);
        $this->assertStringContainsString('>11<', $html);
    }
}
