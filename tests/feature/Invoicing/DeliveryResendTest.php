<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
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
final class DeliveryResendTest extends AuthTestCase
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

    /**
     * @param array<string, string> $data
     */
    private function postForm(string $path, array $data, bool $csrf = true): TestResponse
    {
        $this->withSession();

        return $this->withHeaders(['HX-Request' => 'true'])->call('post', $path, $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    /**
     * @return array<string, string>
     */
    private function valid(string $key, string $to): array
    {
        return ['to' => $to, 'cc' => '', 'subject' => 'Faktura 202610001', 'body' => 'Dobry den', 'request_key' => $key];
    }

    public function testResendSheetRenders(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice(['cc_emails' => ['Ucto@All4net.example']]);
        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/invoices/' . $inv['id'] . '/resend'));

        $this->assertStringContainsString('Odoslať faktúru znova', $html);
        $this->assertStringContainsString('Odoslať', $html);
        $this->assertStringContainsString('x-data="dlRecipients"', $html);
        $this->assertStringContainsString('data-client-emails="fakturacia&#x40;all4net.example,ucto&#x40;all4net.example"', $html);
        $this->assertStringContainsString('name="to"', $html);
        $this->assertMatchesRegularExpression('~action="[^"]*resend"~', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle=|onclick|<script(?![^>]*\bsrc=)/i', $html);
    }

    public function testResendPostRecordsAndRedirects(): void
    {
        $this->login();
        $inv    = $this->issuedInvoice();
        $result = $this->postForm('/invoices/' . $inv['id'] . '/resend', $this->valid('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'ja@gmail.com'));

        $result->assertStatus(200);
        $this->assertSame('/invoices/' . $inv['id'], $result->response()->getHeaderLine('HX-Location'));
        $this->assertSame('resend', (string) $this->owner()->query('SELECT kind FROM invoice_sends')->getRow()->kind);
    }

    public function testResendCrlfInjectionRejected(): void
    {
        $this->login();
        $inv    = $this->issuedInvoice();
        $result = $this->postForm('/invoices/' . $inv['id'] . '/resend', $this->valid('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', "ja@gmail.com\r\nBcc: x@evil.test"));

        $result->assertStatus(422);
        $this->assertSame(0, $this->sendCount());
    }

    public function testResendWithoutCsrfIs403(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();

        $this->postForm('/invoices/' . $inv['id'] . '/resend', $this->valid('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'ja@gmail.com'), false)->assertStatus(403);
        $this->assertSame(0, $this->sendCount());
    }

    public function testExternalInvoiceResendIs404(): void
    {
        $this->login();
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '8.00', '2026-09');
        $id     = $this->insertExternalInvoice($client, '2026-09', [$alloc], 'https://example.com/doc');

        $this->expectException(PageNotFoundException::class);
        $this->get('/invoices/' . $id . '/resend');
    }

    public function testHistoryAndResendButtonOnDetail(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $this->postForm('/invoices/' . $inv['id'] . '/send', $this->valid('dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'fakturacia@all4net.example'));
        $this->postForm('/invoices/' . $inv['id'] . '/resend', $this->valid('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'ja@gmail.com'));

        $html = $this->body($this->get('/invoices/' . $inv['id']));

        $this->assertStringContainsString('Odoslať znova', $html);
        $this->assertStringContainsString('btn-outline-secondary', $html);
        $this->assertStringContainsString('Opätovné odoslanie', $html);
        $this->assertStringContainsString('Odoslanie', $html);
        $this->assertStringContainsString('Klient', $html);
        $this->assertStringContainsString('Iná adresa', $html);
        $this->assertSame(2, substr_count($html, '<details'));
        $this->assertStringContainsString('Message-ID', $html);
        $this->assertStringContainsString('testovacie, neodoslané', $html);
    }
}
