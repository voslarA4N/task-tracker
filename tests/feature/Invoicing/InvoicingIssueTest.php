<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Invoicing\Qr\Lzma;
use App\Libraries\Invoicing\Qr\PayBySquare;
use App\Libraries\Invoicing\Qr\QrPaymentBuilder;
use App\Libraries\Invoicing\Qr\QrPaymentFailed;
use App\Libraries\Invoicing\RendererRegistry;
use App\Libraries\Invoicing\SnapshotBuilder;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\FakePdfRenderer;
use Tests\Support\FakeRateHttpClient;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class InvoicingIssueTest extends AuthTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        Services::injectMock('invoiceIssuer', $this->issuer());
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
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
     * Hodnoty formulara #inv-issue-form z HTML nahladu.
     *
     * @return array<string, mixed>
     */
    private function formFrom(string $html): array
    {
        preg_match_all('/<input type="hidden" name="([a-z_\[\]]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER);
        $out = [];

        foreach ($m as $row) {
            if ($row[1] === 'part_ids[]') {
                $out['part_ids'][] = $row[2];
            } elseif (! in_array($row[1], ['csrf_test_name', csrf_token()], true)) {
                $out[$row[1]] = html_entity_decode($row[2]);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function previewForm(int $client, string $query = ''): array
    {
        return $this->formFrom($this->body($this->get('/invoices/new?client=' . $client . '&month=2026-10' . $query)));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function submit(array $data, bool $csrf = true, bool $htmx = true): TestResponse
    {
        $this->withSession();
        $headers = $htmx ? ['HX-Request' => 'true'] : [];

        return $this->withHeaders($headers)->call('post', '/invoices/issue', $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    private function invoiceCount(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS n FROM invoices')->getRow()->n;
    }

    public function testIssueRedirectsToDetailWithFlash(): void
    {
        $this->login();
        $s      = $this->issueSetup();
        $result = $this->submit($this->previewForm($s['client']));

        $result->assertStatus(200);
        $id = (int) $this->owner()->query("SELECT id FROM invoices WHERE number = '202610001'")->getRow()->id;
        $this->assertSame('/invoices/' . $id, $result->response()->getHeaderLine('HX-Location'));
        $this->assertSame(1, $this->invoiceCount());
        $this->assertSame('Faktúra 202610001 bola vystavená.', session()->getFlashdata('app_notice')['message'] ?? '');
    }

    public function testSameRequestKeyRedirectsToFirstInvoice(): void
    {
        $this->login();
        $s    = $this->issueSetup();
        $form = $this->previewForm($s['client']);

        $first  = $this->submit($form);
        $second = $this->submit($form);

        $this->assertSame($first->response()->getHeaderLine('HX-Location'), $second->response()->getHeaderLine('HX-Location'));
        $this->assertNotSame('', $second->response()->getHeaderLine('HX-Location'));
        $this->assertSame(1, $this->invoiceCount());
    }

    public function testNewRequestKeyForSamePartsIs409WithNumber(): void
    {
        $this->login();
        $s    = $this->issueSetup();
        $form = $this->previewForm($s['client']);
        $this->submit($form)->assertStatus(200);

        $result = $this->submit(['request_key' => $this->uuid4()] + $form);

        $result->assertStatus(409);
        $this->assertStringContainsString('Časti už sú na vystavenej faktúre 202610001', $this->body($result));
        $this->assertStringContainsString('Zobraziť faktúru 202610001', $this->body($result));
        $this->assertSame(1, $this->invoiceCount());
    }

    public function testChangedHoursAfterPreviewIs409(): void
    {
        $this->login();
        $s    = $this->issueSetup();
        $form = $this->previewForm($s['client']);
        $this->owner()->query("UPDATE billing_allocations SET hours = '41.00', amount = '1107.00' WHERE id = :p:", ['p' => $s['parts'][0]]);

        $result = $this->submit($form);

        $result->assertStatus(409);
        $this->assertStringContainsString('Náhľad sa medzitým zmenil', $this->body($result));
        $this->assertSame(0, $this->invoiceCount());
    }

    public function testBackdateRequiresConfirmation(): void
    {
        $this->login();
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.00', '2026-10']]]);
        $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]));
        $form = $this->previewForm($s['client'], '&issue_date=2026-10-20');

        $denied = $this->submit($form);

        $denied->assertStatus(422);
        $this->assertStringContainsString('Potvrďte vystavenie so spätným dátumom.', $this->body($denied));
        $this->assertSame(1, $this->invoiceCount());

        $this->submit($form + ['confirm_backdate' => '1'])->assertStatus(200);
        $this->assertSame(2, $this->invoiceCount());
    }

    public function testForeignCurrencyWithoutRateByDirectPostIs422(): void
    {
        Services::injectMock('rateHttpClient', new FakeRateHttpClient());
        $this->login();
        $s = $this->issueSetup();
        $this->owner()->query("UPDATE clients SET currency = 'PLN' WHERE id = :c:", ['c' => $s['client']]);
        $this->owner()->query("UPDATE billing_allocations SET currency = 'PLN' WHERE id = :p:", ['p' => $s['parts'][0]]);
        $form = ['preview_token' => str_repeat('a', 64)] + $this->previewForm($s['client']);

        $result = $this->submit($form);

        $result->assertStatus(422);
        $this->assertStringContainsString('Pred vystavením je potrebný kurz', $this->body($result));
        $this->assertSame(0, $this->invoiceCount());
    }

    public function testInvalidFormIs422AndWritesNothing(): void
    {
        $this->login();
        $s    = $this->issueSetup();
        $form = ['preview_token' => 'x'] + $this->previewForm($s['client']);

        $this->submit($form)->assertStatus(422);
        $this->assertSame(0, $this->invoiceCount());
    }

    public function testPostedTotalIsIgnored(): void
    {
        $this->login();
        $s = $this->issueSetup();

        $this->submit($this->previewForm($s['client']) + ['total' => '1.00', 'number' => '999']);

        $this->assertSame('1080.00', (string) $this->owner()->query("SELECT total FROM invoices WHERE number = '202610001'")->getRow()->total);
    }

    public function testNonHtmxPostRedirects(): void
    {
        $this->login();
        $s      = $this->issueSetup();
        $result = $this->submit($this->previewForm($s['client']), true, false);

        $result->assertStatus(302);
        $this->assertStringContainsString('/invoices/', $result->response()->getHeaderLine('Location'));
    }

    public function testPostWithoutCsrfIs403(): void
    {
        $this->login();
        $s = $this->issueSetup();

        $this->submit($this->previewForm($s['client']), false)->assertStatus(403);
        $this->assertSame(0, $this->invoiceCount());
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->withSession();
        $result = $this->call('post', '/invoices/issue', [csrf_token() => csrf_hash()]);

        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testIssueStoresPaymentMethodAndNote(): void
    {
        $this->login();
        $s = $this->issueSetup();
        $this->submit(['payment_method' => 'cash', 'payment_note' => "Hotovost pri prevzati\r\n"] + $this->previewForm($s['client']))->assertStatus(200);

        $row = $this->owner()->query("SELECT payment_method, payment_note, snapshot->'payment'->>'method' AS sm FROM invoices")->getRowArray();
        $this->assertSame('cash', $row['payment_method']);
        $this->assertSame('Hotovost pri prevzati', $row['payment_note']);
        $this->assertSame('cash', $row['sm']);
    }

    public function testInvalidPaymentMethodIs422WithoutWrite(): void
    {
        $this->login();
        $s      = $this->issueSetup();
        $result = $this->submit(['payment_method' => 'bitcoin'] + $this->previewForm($s['client']));

        $result->assertStatus(422);
        $this->assertStringContainsString('Vyberte platnú formu úhrady.', $this->body($result));
        $this->assertSame(0, $this->invoiceCount());
    }

    public function testTooLongPaymentNoteIs422WithoutWrite(): void
    {
        $this->login();
        $s      = $this->issueSetup();
        $result = $this->submit(['payment_note' => str_repeat('a', 501)] + $this->previewForm($s['client']));

        $result->assertStatus(422);
        $this->assertStringContainsString('Poznámka môže mať najviac 500 znakov.', $this->body($result));
        $this->assertSame(0, $this->invoiceCount());
    }

    public function testChangingPaymentAfterPreviewIsNotStale(): void
    {
        $this->login();
        $s    = $this->issueSetup();
        $form = $this->previewForm($s['client']);
        $this->submit(['payment_method' => 'card', 'payment_note' => 'Ina poznamka'] + $form)->assertStatus(200);
        $this->assertSame(1, $this->invoiceCount());
    }

    public function testQrFailureIs500WithMessageAndNothingWritten(): void
    {
        $this->login();
        $s    = $this->issueSetup();
        $lzma = new class () implements Lzma {
            public function compress(string $raw): string
            {
                throw new QrPaymentFailed('boom', QrPaymentFailed::COMPRESSION_FAILED);
            }
        };
        $issuer = new InvoiceIssuer($this->rw(), null, new RendererRegistry(['inv-2' => new FakePdfRenderer('inv-2'), 'rep-1' => new FakePdfRenderer('rep-1')]), new SnapshotBuilder($this->rw(), null, null, null, new QrPaymentBuilder(new PayBySquare($lzma))));
        Services::injectMock('invoiceIssuer', $issuer);

        $form   = $this->previewForm($s['client']);
        $result = $this->submit($form);

        $result->assertStatus(500);
        $this->assertStringContainsString('QR kód pre platbu sa nepodarilo vytvoriť', $this->body($result));
        $this->assertStringContainsString('nástroj xz chýba alebo zlyhal', $this->body($result));
        $this->assertStringNotContainsString('Skontrolujte IBAN', $this->body($result));
        $this->assertSame(0, $this->invoiceCount());
    }
}
