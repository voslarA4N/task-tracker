<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Uuid;
use App\Libraries\Receivables\PaymentService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\FakeRateHttpClient;
use Tests\Support\FxFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class CreditNotesControllerTest extends AuthTestCase
{
    use CreditNoteFixtures;
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));
        Services::injectMock('rateHttpClient', new FakeRateHttpClient());
        Services::injectMock('creditNoteIssuer', $this->creditNoteIssuer());
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        $this->restoreFxSettings();
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

    private function assertNotFound(callable $request): void
    {
        try {
            $result = $request();
            $this->assertSame(404, $result->response()->getStatusCode());
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    private function issuedInvoice(): int
    {
        $s = $this->issueSetup(['parts' => [['40.00', '2026-10'], ['10.00', '2026-10']]]);

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
    }

    private function issuedForeignInvoice(): int
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-30']))->id;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function submit(string $url, array $data = [], bool $csrf = true, bool $htmx = true): TestResponse
    {
        $this->withSession();
        $headers = $htmx ? ['HX-Request' => 'true'] : [];

        return $this->withHeaders($headers)->call('post', $url, $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    /**
     * @return array<string, string>
     */
    private function validForm(): array
    {
        return ['correction_type' => 'amount_error', 'reason' => 'Chybná sadzba', 'issue_date' => '2026-10-31'];
    }

    public function testPageRendersFormAndPreview(): void
    {
        $this->login();
        $id = $this->issuedInvoice();

        $result = $this->get('/invoices/' . $id . '/credit-note');

        $result->assertStatus(200);
        $html = $this->body($result);
        $this->assertStringContainsString('Vystaviť dobropis', $html);
        $this->assertStringContainsString('name="correction_type"', $html);
        $this->assertSame(4, preg_match_all('/<option value="[a-z_]+"/', $html));
        $this->assertStringNotContainsString('fx_error', $html);
        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('maxlength="500"', $html);
        $this->assertMatchesRegularExpression('/name="issue_date"[^>]*value="2026-10-31"/', $html);
        $this->assertMatchesRegularExpression('/min="2026-10-27"/', $html);
        $this->assertStringContainsString('Číslo sa pridelí pri vystavení.', $html);
        $this->assertStringContainsString('DB-202610001', $html);
        $this->assertStringContainsString('(počet 2)', $html);
        $this->assertStringContainsString('spolu: 0,00', $html);
        $this->assertStringContainsString('-1', $html);
        $this->assertStringNotContainsString('Prijaté úhrady', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>\s*(<span[^>]*><\/span>\s*)?Pokračovať/', $html);
    }

    public function testPaymentsWarning(): void
    {
        $this->login();
        $id = $this->issuedInvoice();
        (new PaymentService($this->rw()))->record($id, '100.00', '2026-10-30', null, Uuid::v4(), null);

        $html = $this->body($this->get('/invoices/' . $id . '/credit-note'));

        $this->assertStringContainsString('Prijaté úhrady', $html);
        $this->assertStringContainsString('Vrátenie peňazí klientovi sa rieši mimo aplikácie.', $html);
    }

    public function testForeignCurrencyShowsRateHintAndFxOption(): void
    {
        $this->login();
        $id = $this->issuedForeignInvoice();

        $html = $this->body($this->get('/invoices/' . $id . '/credit-note'));

        $this->assertStringContainsString('Kurz sa preberá z pôvodnej faktúry.', $html);
        $this->assertStringContainsString('Spolu v', $html);
        $this->assertSame(5, preg_match_all('/<option value="[a-z_]+"/', $html));
        $this->assertStringContainsString('fx_error', $html);
    }

    public function testExternalAndCreditNoteAre404(): void
    {
        $this->login();
        $s        = $this->issueSetup();
        $external = $this->insertExternalInvoice($s['client'], '2026-10', $s['parts']);

        $this->assertNotFound(fn () => $this->get('/invoices/' . $external . '/credit-note'));

        $s2 = $this->issueSetup(['parts' => [['10.00', '2026-11']]]);
        $id = $this->issuer()->issue($this->issueCommand($s2['client'], '2026-11', $s2['entity'], $s2['parts']))->id;
        $cn = $this->issueCreditNote($id);

        $this->assertNotFound(fn () => $this->get('/invoices/' . $cn->id . '/credit-note'));
        $this->assertNotFound(fn () => $this->get('/invoices/999999/credit-note'));
    }

    public function testAlreadyCreditedIs409WithLink(): void
    {
        $this->login();
        $id = $this->issuedInvoice();
        $cn = $this->issueCreditNote($id);

        $result = $this->get('/invoices/' . $id . '/credit-note');

        $result->assertStatus(409);
        $html = $this->body($result);
        $this->assertStringContainsString('Táto faktúra už bola stornovaná dobropisom ' . $cn->number . '.', $html);
        $this->assertStringContainsString('href="/invoices/' . $cn->id . '"', $html);
        $this->assertStringNotContainsString('name="correction_type"', $html);
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $id = $this->issuedInvoice();

        $result = $this->get('/invoices/' . $id . '/credit-note');

        $result->assertRedirect();
        $this->assertStringContainsString('login', $result->getRedirectUrl());
    }

    public function testPreviewWithInvalidDateIs422(): void
    {
        $this->login();
        $id = $this->issuedInvoice();

        $result = $this->submit('/invoices/' . $id . '/credit-note/preview', ['issue_date' => '2026-13-45'] + $this->validForm());

        $result->assertStatus(422);
        $this->assertStringContainsString('is-invalid', $this->body($result));
        $this->assertStringContainsString('Zadajte platný dátum vystavenia.', $this->body($result));
    }

    public function testPreviewWithTypeAndReasonEnablesContinue(): void
    {
        $this->login();
        $id = $this->issuedInvoice();

        $ok = $this->submit('/invoices/' . $id . '/credit-note/preview', $this->validForm());

        $ok->assertStatus(200);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*id="cn-continue"[^>]*disabled/', $this->body($ok));
        $this->assertMatchesRegularExpression('/<button[^>]*id="cn-continue"/', $this->body($ok));

        $without = $this->submit('/invoices/' . $id . '/credit-note/preview', ['correction_type' => ''] + $this->validForm());

        $this->assertMatchesRegularExpression('/<button[^>]*id="cn-continue"[^>]*disabled/', $this->body($without));
    }

    public function testBackdatePreviewShowsWarning(): void
    {
        $this->login();
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.00', '2026-10']]]);
        $a = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]))->id;
        $b = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]]))->id;
        $this->issueCreditNote($a, ['issueDate' => '2026-10-31']);

        $result = $this->submit('/invoices/' . $b . '/credit-note/preview', ['issue_date' => '2026-10-30'] + $this->validForm());

        $result->assertStatus(200);
        $this->assertStringContainsString('Pozor: dátum vystavenia', $this->body($result));
        $this->assertStringContainsString('name="confirm_backdate"', $this->body($result));
    }
}
