<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\DeliveryFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class ReminderTest extends AuthTestCase
{
    use DeliveryFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
        $this->configureMail('log');
    }

    protected function tearDown(): void
    {
        $this->resetMail();
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
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

    private function laterClock(): void
    {
        Services::injectMock('clock', new FrozenClock('2026-12-01T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    /**
     * Odoslana faktura po splatnosti.
     */
    private function overdueInvoice(): int
    {
        $inv = $this->issuedInvoice();
        $this->mailer()->send($this->sendCommand($inv['id']));
        $this->laterClock();

        return $inv['id'];
    }

    /**
     * @param array<string, string> $data
     */
    private function postRemind(int $id, array $data, bool $csrf = true): TestResponse
    {
        $this->withSession();

        return $this->withHeaders(['HX-Request' => 'true'])->call('post', '/invoices/' . $id . '/remind', $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    /**
     * @return array<string, string>
     */
    private function valid(string $returnTo = '/'): array
    {
        return ['to' => 'fakturacia@all4net.example', 'cc' => '', 'subject' => 'Upomienka: faktura 202610001', 'body' => 'Dobry den', 'request_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'return_to' => $returnTo];
    }

    public function testDashboardRowOffersReminderAndSheetRenders(): void
    {
        $this->login();
        $id = $this->overdueInvoice();

        $html = $this->body($this->get('/?client=all'));
        $this->assertStringContainsString('hx-get="/invoices/' . $id . '/remind?return_to=/"', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle=|onclick/i', $html);

        $sheet = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/invoices/' . $id . '/remind?return_to=/'));
        $this->assertStringContainsString('Upomienka&#x3A;&#x20;fakt&#xFA;ra&#x20;202610001', $sheet);
        $this->assertStringContainsString('name="return_to" value="&#x2F;"', $sheet);
        $this->assertSame(1, substr_count($sheet, 'dl-attach-name'));
        $this->assertMatchesRegularExpression('~action="[^"]*remind"~', $sheet);
    }

    public function testPostRecordsReminderAndShowsLastReminder(): void
    {
        $this->login();
        $id = $this->overdueInvoice();

        $result = $this->postRemind($id, $this->valid());
        $result->assertStatus(200);
        $this->assertSame('/', $result->response()->getHeaderLine('HX-Location'));
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM invoice_sends WHERE kind = 'reminder'")->getRow()->c);

        $detail = $this->body($this->get('/invoices/' . $id));
        $this->assertStringContainsString('posledná upomienka', $detail);
        $this->assertStringContainsString('/invoices/' . $id . '/remind', $detail);

        $dash = $this->body($this->get('/?client=all'));
        $this->assertStringContainsString('Posledná upomienka', $dash);
    }

    public function testEvilReturnToFallsBackToDetail(): void
    {
        $this->login();
        $id = $this->overdueInvoice();

        $result = $this->postRemind($id, $this->valid('https://evil.example'));
        $this->assertSame('/invoices/' . $id, $result->response()->getHeaderLine('HX-Location'));
    }

    public function testPaidInvoiceHasNoButtonAndPostIs409(): void
    {
        $this->login();
        $id    = $this->overdueInvoice();
        $total = (string) $this->owner()->query('SELECT total FROM invoices WHERE id = :i:', ['i' => $id])->getRow()->total;
        $this->owner()->query('INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, :d:, :a:, now())', ['i' => $id, 'd' => '2026-11-02', 'a' => $total]);

        $this->assertStringNotContainsString('/remind', $this->body($this->get('/invoices/' . $id)));
        $this->postRemind($id, $this->valid())->assertStatus(409);
        $this->withHeaders(['HX-Request' => 'true'])->get('/invoices/' . $id . '/remind')->assertStatus(409);
        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS c FROM invoice_sends WHERE kind = 'reminder'")->getRow()->c);
    }

    public function testWithoutCsrfIs403(): void
    {
        $this->login();
        $id = $this->overdueInvoice();

        $this->postRemind($id, $this->valid(), false)->assertStatus(403);
    }
}
