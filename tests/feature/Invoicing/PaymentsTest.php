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
final class PaymentsTest extends AuthTestCase
{
    use DeliveryFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useClock('2026-10-27T10:00:00+01:00');
        $this->resetMasterTables();
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

    private function key(): string
    {
        return $this->uuid4();
    }

    private function total(int $id): string
    {
        return (string) $this->owner()->query('SELECT total FROM invoices WHERE id = :i:', ['i' => $id])->getRow()->total;
    }

    private function rows(string $table): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS n FROM ' . $table)->getRow()->n;
    }

    /**
     * @param array<string, string> $data
     */
    private function pay(int $id, array $data = [], bool $csrf = true, bool $htmx = true): TestResponse
    {
        $this->withSession();
        $data += ['paid_on' => '2026-10-27', 'amount' => '', 'note' => '', 'request_key' => $this->key(), 'return_to' => '/invoices/' . $id];

        return $this->withHeaders($htmx ? ['HX-Request' => 'true'] : [])->call('post', '/invoices/' . $id . '/payments', $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    private function paymentId(): int
    {
        return (int) $this->owner()->query('SELECT id FROM payments ORDER BY id DESC LIMIT 1')->getRow()->id;
    }

    /**
     * @param array<string, string> $data
     */
    private function void(int $paymentId, array $data, bool $csrf = true): TestResponse
    {
        $this->withSession();

        return $this->withHeaders(['HX-Request' => 'true'])->call('post', '/payments/' . $paymentId . '/void', $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    private function assertNoInline(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/\sstyle=|onclick|<script(?![^>]*\bsrc=)/i', $html);
    }

    private function detail(int $id): string
    {
        return $this->body($this->get('/invoices/' . $id));
    }

    public function testFormShowsBalancePlaceholder(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice();
        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/invoices/' . $inv['id'] . '/payments'));

        $this->assertStringContainsString('id="work-sheet"', $html);
        $this->assertStringContainsString('name="request_key"', $html);
        $this->assertStringContainsString('value="2026-10-27"', $html);
        $this->assertMatchesRegularExpression('/placeholder="1&#xA0;080/u', $html);
        $this->assertStringContainsString('data-submit-once', $html);
        $this->assertNoInline($html);
    }

    public function testFormWithoutHtmxIsFullPage(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice();
        $html = $this->body($this->get('/invoices/' . $inv['id'] . '/payments'));

        $this->assertStringContainsString('<html', $html);
        $this->assertNoInline($html);
    }

    public function testBlankAmountPaysWholeBalance(): void
    {
        $this->login();
        $inv    = $this->issuedInvoice();
        $result = $this->pay($inv['id']);

        $result->assertStatus(200);
        $this->assertSame('/invoices/' . $inv['id'], $result->response()->getHeaderLine('HX-Location'));
        $this->assertSame('allocationChanged', $result->response()->getHeaderLine('HX-Trigger'));
        $this->assertSame(1, $this->rows('payments'));
        $this->assertSame($this->total($inv['id']), (string) $this->owner()->query('SELECT amount FROM payments')->getRow()->amount);
        $this->assertSame('Faktúra je uhradená.', session()->getFlashdata('app_notice')['message'] ?? '');

        $html = $this->detail($inv['id']);
        $this->assertStringContainsString('Uhradená', $html);
        $this->assertMatchesRegularExpression('/Zostatok\s+0,00/u', $html);
    }

    public function testPartialPaymentFlashAndDetail(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $this->pay($inv['id'], ['amount' => '500']);

        $this->assertStringContainsString('500,00', session()->getFlashdata('app_notice')['message'] ?? '');
        $html = $this->detail($inv['id']);
        $this->assertStringContainsString('Čiastočne uhradená', $html);
        $this->assertStringContainsString('Zostatok', $html);
        $this->assertStringContainsString('Stornovať', $html);
        $this->assertNoInline($html);
    }

    public function testImpactPreview(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $this->withSession();
        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->call('post', '/invoices/' . $inv['id'] . '/payments/impact', ['amount' => '500', csrf_token() => csrf_hash()]));

        $this->assertStringContainsString('Po zápise: zostatok', $html);
        $this->assertStringContainsString('Čiastočne uhradená', $html);
        $this->assertSame(0, $this->rows('payments'));
    }

    public function testOverpaymentIs422WithBalance(): void
    {
        $this->login();
        $inv    = $this->issuedInvoice();
        $result = $this->pay($inv['id'], ['amount' => '99999']);

        $result->assertStatus(422);
        $html = $this->body($result);
        $this->assertStringContainsString('Suma je vyššia ako zostatok', $html);
        $this->assertStringContainsString('is-invalid', $html);
        $this->assertSame(0, $this->rows('payments'));
    }

    public function testNonPositiveAndFutureDateAre422(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();

        $this->pay($inv['id'], ['amount' => '0'])->assertStatus(422);
        $this->pay($inv['id'], ['amount' => '-5'])->assertStatus(422);
        $future = $this->pay($inv['id'], ['paid_on' => '2026-10-28']);
        $future->assertStatus(422);
        $this->assertStringContainsString('Dátum úhrady nemôže byť v budúcnosti.', $this->body($future));
        $this->assertSame(0, $this->rows('payments'));
    }

    public function testPaymentBeforeIssueDateIs422AndFormHasMin(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();

        $form = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/invoices/' . $inv['id'] . '/payments'));
        $this->assertStringContainsString('min="2026-10-27"', $form);

        $early = $this->pay($inv['id'], ['paid_on' => '2025-10-29']);
        $early->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Payments.errorBeforeIssue')), $this->body($early));
        $this->assertSame(0, $this->rows('payments'));
    }

    public function testPostWithoutCsrfIs403(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();

        $this->pay($inv['id'], [], false)->assertStatus(403);
        $this->assertSame(0, $this->rows('payments'));
    }

    public function testRepeatedRequestKeyWritesOnce(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice();
        $data = ['amount' => '100', 'request_key' => $this->key()];

        $this->pay($inv['id'], $data);
        $this->pay($inv['id'], $data);

        $this->assertSame(1, $this->rows('payments'));
    }

    public function testReplayAfterFullPaymentReturnsOriginalResultNot404(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice();
        $data = ['request_key' => $this->key()];

        $this->pay($inv['id'], $data)->assertStatus(200);
        $this->assertSame('paid', $this->owner()->query('SELECT status FROM invoice_status_v WHERE id = ?', [$inv['id']])->getRow()->status);

        // odpoved sa stratila, pouzivatel posle formular znova (WR-02), aj bez htmx
        $again = $this->pay($inv['id'], $data);
        $again->assertStatus(200);
        $this->assertSame('/invoices/' . $inv['id'], $again->response()->getHeaderLine('HX-Location'));
        $this->assertSame('Faktúra je uhradená.', session()->getFlashdata('app_notice')['message'] ?? '');

        $this->pay($inv['id'], $data, true, false)->assertRedirectTo('/invoices/' . $inv['id']);
        $this->assertSame(1, $this->rows('payments'));
    }

    public function testRequestKeyOfOtherInvoiceIsNotReplayed(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice();
        $data = ['request_key' => $this->key()];
        $this->pay($inv['id'], $data);

        $this->expectException(PageNotFoundException::class);
        $this->pay(999999, $data);
    }

    public function testForeignReturnToFallsBackToDetail(): void
    {
        $this->login();
        $inv    = $this->issuedInvoice();
        $result = $this->pay($inv['id'], ['amount' => '100', 'return_to' => 'https://evil.example']);

        $this->assertSame('/invoices/' . $inv['id'], $result->response()->getHeaderLine('HX-Location'));

        $inv2 = $this->pay($inv['id'], ['amount' => '100', 'return_to' => '/'], true, true);
        $this->assertSame('/', $inv2->response()->getHeaderLine('HX-Location'));
    }

    public function testVoidRequiresReasonAndKeepsRow(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $this->pay($inv['id'], ['amount' => '500']);
        $pid = $this->paymentId();

        $form = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/payments/' . $pid . '/void'));
        $this->assertStringContainsString('btn-danger', $form);
        $this->assertNoInline($form);

        $empty = $this->void($pid, ['reason' => '']);
        $empty->assertStatus(422);
        $this->assertStringContainsString('Zadajte dôvod storna.', $this->body($empty));
        $this->assertSame(0, $this->rows('payment_voids'));

        $ok = $this->void($pid, ['reason' => 'Omyl pri zadávaní']);
        $ok->assertStatus(200);
        $this->assertSame('/invoices/' . $inv['id'], $ok->response()->getHeaderLine('HX-Location'));
        $this->assertSame(1, $this->rows('payment_voids'));

        $this->void($pid, ['reason' => 'znova'])->assertStatus(409);

        $html = $this->detail($inv['id']);
        $this->assertStringContainsString('pay-void', $html);
        $this->assertStringContainsString('Omyl pri zadávaní', $html);
        $this->assertStringNotContainsString('Čiastočne uhradená', $html);
        $this->assertNoInline($html);
    }

    public function testVoidWithoutCsrfIs403(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $this->pay($inv['id'], ['amount' => '500']);

        $this->void($this->paymentId(), ['reason' => 'x'], false)->assertStatus(403);
    }

    public function testOverdueBadgeFromClock(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();

        $this->useClock('2026-11-10T10:00:00+01:00');
        $day = $this->detail($inv['id']);
        $this->assertStringContainsString('Odoslať klientovi', $day);
        $this->assertStringNotContainsString('Po splatnosti', $day);

        $this->useClock('2026-11-15T10:00:00+01:00');
        $this->assertStringContainsString('Po splatnosti · 5 dní', $this->detail($inv['id']));
    }

    public function testActionsFollowStatus(): void
    {
        $this->login();
        $inv  = $this->issuedInvoice();
        $html = $this->detail($inv['id']);

        $this->assertMatchesRegularExpression('#btn btn-primary btn-touch" href="/invoices/\d+/send#', $html);
        $this->assertMatchesRegularExpression('#btn btn-outline-secondary btn-touch" href="/invoices/\d+/payments#', $html);

        $this->pay($inv['id']);
        $paid = $this->detail($inv['id']);
        // zaplatena, ale neodoslana: stale ponuka Odoslat, nie Znova poslat (WR-01)
        $this->assertStringContainsString('href="/invoices/' . $inv['id'] . '/send"', $paid);
        $this->assertStringNotContainsString('/resend', $paid);
        $this->assertStringNotContainsString('href="/invoices/' . $inv['id'] . '/payments"', $paid);
    }

    public function testExternalInvoiceHasNoPaymentUi(): void
    {
        $this->login();
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '8.00', '2026-09');
        $id     = $this->insertExternalInvoice($client, '2026-09', [$alloc], 'https://example.com/doc');

        $html = $this->detail($id);
        $this->assertStringNotContainsString('Zapísať úhradu', $html);
        $this->assertStringNotContainsString('inv-pay-title', $html);
        $this->expectException(PageNotFoundException::class);
        $this->get('/invoices/' . $id . '/payments');
    }
}
