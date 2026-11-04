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
use Tests\Support\RegenerateFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class DeliverySendTest extends AuthTestCase
{
    use DeliveryFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;
    use RegenerateFixtures;

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

    private function requestKey(string $html): string
    {
        $this->assertSame(1, preg_match('#name="request_key" value="([0-9a-f-]{36})"#', $html, $m));

        return $m[1];
    }

    /**
     * @param array<string, array<string, string>|string> $data
     */
    private function submit(int $id, array $data, bool $csrf = true, bool $htmx = true): TestResponse
    {
        $this->withSession();
        $headers = $htmx ? ['HX-Request' => 'true'] : [];

        return $this->withHeaders($headers)->call('post', '/invoices/' . $id . '/send', $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    /**
     * @return array<string, array<string, string>|string>
     */
    private function valid(string $key, string $to = 'fakturacia@all4net.example'): array
    {
        return ['to' => $to, 'cc' => '', 'subject' => 'Faktura 202610001', 'body' => "Dobry den,\n\nv prilohe faktura.", 'request_key' => $key, 'attachment_versions' => ['invoice' => '1', 'report' => '1']];
    }

    private function externalInvoice(): int
    {
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '8.00', '2026-09');

        return $this->insertExternalInvoice($client, '2026-09', [$alloc], 'https://example.com/doc');
    }

    private function assertNoInline(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/\sstyle=|onclick|<script(?![^>]*\bsrc=)/i', $html);
    }

    public function testSendFormIsPrefilled(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice();
        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/invoices/' . $inv['id'] . '/send'));

        $this->assertStringContainsString('id="work-sheet"', $html);
        $this->assertStringContainsString('value="fakturacia&#x40;all4net.example"', $html);
        $this->assertStringContainsString('202610001', $html);
        $this->assertSame(2, substr_count($html, '.pdf'));
        $this->assertStringContainsString('name="request_key"', $html);
        $this->assertStringContainsString('name="csrf_test_name"', str_replace(csrf_token(), 'csrf_test_name', $html));
        $this->assertNoInline($html);
        $this->requestKey($html);
    }

    public function testSendFormWithoutHtmxIsFullPage(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice();
        $html = $this->body($this->get('/invoices/' . $inv['id'] . '/send'));

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('data-submit-once', $html);
        $this->assertNoInline($html);
    }

    public function testPostWithoutCsrfIs403(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();

        $this->submit($inv['id'], $this->valid('11111111-1111-4111-8111-111111111111'), false)->assertStatus(403);
        $this->assertSame(0, $this->sendCount());
    }

    public function testValidPostRedirectsWithFlash(): void
    {
        $this->login();
        $inv    = $this->issuedInvoice();
        $result = $this->submit($inv['id'], $this->valid('22222222-2222-4222-8222-222222222222'), true, false);

        $result->assertStatus(302);
        $this->assertStringEndsWith('/invoices/' . $inv['id'], $result->response()->getHeaderLine('Location'));
        $this->assertSame(1, $this->sendCount());
        $this->assertStringContainsString('testovacie prostredie', session()->getFlashdata('app_notice')['message'] ?? '');
    }

    public function testFormCarriesConfirmedAttachmentVersions(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $this->regenerator()->regenerate($inv['id'], 'report', 1);

        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->call('get', '/invoices/' . $inv['id'] . '/send'));

        $this->assertStringContainsString('name="attachment_versions[invoice]" value="1"', $html);
        $this->assertStringContainsString('name="attachment_versions[report]" value="2"', $html);
    }

    public function testStaleConfirmedVersionIsRejectedAndFormRefreshed(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $this->regenerator()->regenerate($inv['id'], 'report', 1);

        $result = $this->submit($inv['id'], $this->valid('55555555-5555-4555-8555-555555555555'));

        $result->assertStatus(409);
        $html = $this->body($result);
        $this->assertStringContainsString('pregenerovaný', $html);
        $this->assertStringContainsString('name="attachment_versions[report]" value="2"', $html);
        $this->assertSame(0, $this->sendCount());

        $data                        = $this->valid('55555555-5555-4555-8555-555555555555');
        $data['attachment_versions'] = ['invoice' => '1', 'report' => '2'];
        $this->submit($inv['id'], $data)->assertStatus(200);
        $this->assertSame(1, $this->sendCount());
    }

    public function testStaleVersionsWithBadAddressNeverSilentlyReconfirm(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $this->regenerator()->regenerate($inv['id'], 'report', 1);

        // WR-01: stale confirmation + typo: no 422 re-render that would adopt v2 without the alert
        $result = $this->submit($inv['id'], $this->valid('77777777-7777-4777-8777-777777777777', 'not-an-address'));
        $result->assertStatus(409);
        $this->assertStringContainsString('pregenerovaný', $this->body($result));

        // the stale form is posted again with a valid address: still rejected, nothing sent
        $this->submit($inv['id'], $this->valid('77777777-7777-4777-8777-777777777777'))->assertStatus(409);
        $this->assertSame(0, $this->sendCount());
    }

    public function testValidationErrorKeepsConfirmedVersionsThenSends(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();

        $result = $this->submit($inv['id'], $this->valid('88888888-8888-4888-8888-888888888888', 'not-an-address'));
        $result->assertStatus(422);
        $this->assertStringContainsString('name="attachment_versions[report]" value="1"', $this->body($result));

        $this->submit($inv['id'], $this->valid('88888888-8888-4888-8888-888888888888'))->assertStatus(200);
        $this->assertSame(1, $this->sendCount());
    }

    public function testPostWithoutConfirmedVersionsIsRejected(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice();
        $data = $this->valid('66666666-6666-4666-8666-666666666666');
        unset($data['attachment_versions']);

        $result = $this->submit($inv['id'], $data);
        $result->assertStatus(409);
        $html = $this->body($result);
        $this->assertStringContainsString('zastaraný', $html);
        $this->assertStringNotContainsString('pregenerovaný', $html);
        $this->assertSame(0, $this->sendCount());
    }

    public function testHtmxPostReturnsLocationAndTrigger(): void
    {
        $this->login();
        $inv    = $this->issuedInvoice();
        $result = $this->submit($inv['id'], $this->valid('33333333-3333-4333-8333-333333333333'));

        $result->assertStatus(200);
        $this->assertSame('/invoices/' . $inv['id'], $result->response()->getHeaderLine('HX-Location'));
        $this->assertSame('allocationChanged', $result->response()->getHeaderLine('HX-Trigger'));
    }

    public function testRepeatedRequestKeyDoesNotSendTwice(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice();
        $data = $this->valid('44444444-4444-4444-8444-444444444444');

        $this->submit($inv['id'], $data);
        $this->submit($inv['id'], $data);

        $this->assertSame(1, $this->sendCount());
    }

    public function testInvalidAddressIs422WithIsInvalid(): void
    {
        $this->login();
        $inv    = $this->issuedInvoice();
        $result = $this->submit($inv['id'], $this->valid('55555555-5555-4555-8555-555555555555', 'not-an-address'));

        $result->assertStatus(422);
        $html = $this->body($result);
        $this->assertStringContainsString('is-invalid', $html);
        $this->assertStringContainsString('invalid-feedback', $html);
        $this->assertStringContainsString('value="not-an-address"', $html);
        $this->assertStringContainsString('55555555-5555-4555-8555-555555555555', $html);
        $this->assertSame(0, $this->sendCount());
    }

    public function testSubjectWithLineBreakIs422WithFieldError(): void
    {
        $this->login();
        $inv    = $this->issuedInvoice();
        $result = $this->submit($inv['id'], ['subject' => "A\r\nBcc: x@evil.example"] + $this->valid('66666666-6666-4666-8666-666666666666'));

        $result->assertStatus(422);
        $html = $this->body($result);
        $this->assertStringContainsString('is-invalid', $html);
        $this->assertStringContainsString(esc(lang('Deliveries.errorSubjectChars')), $html);
        $this->assertSame(0, $this->sendCount());
    }

    public function testExternalInvoiceSendIs404(): void
    {
        $this->login();
        $id = $this->externalInvoice();

        $this->expectException(PageNotFoundException::class);
        $this->get('/invoices/' . $id . '/send')->assertStatus(404);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $result = $this->get('/invoices/1/send');

        $result->assertRedirect();
        $this->assertStringContainsString('login', $result->response()->getHeaderLine('Location'));
    }

    public function testDetailOfIssuedInvoiceOffersSending(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice();
        $html = $this->body($this->get('/invoices/' . $inv['id']));

        $this->assertStringContainsString('Vystavená', $html);
        $this->assertStringContainsString('Faktúra 202610001 je vystavená. Odošlite ju klientovi.', $html);
        $this->assertStringContainsString('hx-get="/invoices/' . $inv['id'] . '/send"', $html);
        $this->assertStringContainsString('id="sheet"', $html);
        $this->assertStringContainsString('Zatiaľ neodoslané', $html);
        $this->assertNoInline($html);
    }

    public function testDetailAfterSendShowsSentAndHistory(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $this->submit($inv['id'], $this->valid('66666666-6666-4666-8666-666666666666'));
        $html = $this->body($this->get('/invoices/' . $inv['id']));

        $this->assertStringContainsString('Odoslaná', $html);
        $this->assertStringContainsString('1× odoslaná', $html);
        $this->assertMatchesRegularExpression('/Odoslaná 27\. 10\. 2026/u', $html);
        $this->assertStringContainsString('Komu: fakturacia@all4net.example', $html);
        $this->assertStringNotContainsString('hx-get="/invoices/' . $inv['id'] . '/send"', $html);
        $this->assertNoInline($html);
    }

    public function testExternalDetailHasNoSendButton(): void
    {
        $this->login();
        $html = $this->body($this->get('/invoices/' . $this->externalInvoice()));

        $this->assertStringNotContainsString('/send', $html);
        $this->assertStringNotContainsString('Odoslať klientovi', $html);
        $this->assertNoInline($html);
    }
}
