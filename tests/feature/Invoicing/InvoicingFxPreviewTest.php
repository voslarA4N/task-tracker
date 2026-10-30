<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\FakeRateHttpClient;
use Tests\Support\FxFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * Nahlad a vystavenie faktury v cudzej mene (Phase 8, CUR-02, CUR-03, D-07, D-12).
 *
 * @internal
 */
#[Group('feature')]
final class InvoicingFxPreviewTest extends AuthTestCase
{
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private const URL = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-20/2026-10-30/?format=json';

    private FakeRateHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-30T10:00:00+01:00'));
        Services::injectMock('cache', new MockCache());
        $this->http = new FakeRateHttpClient();
        $this->http->respond(self::URL, 200, FakeRateHttpClient::fixture('nbp_eur_range.json'));
        Services::injectMock('rateHttpClient', $this->http);
        Services::injectMock('invoiceIssuer', $this->issuer());
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->restoreFxSettings();
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

    private function body(TestResponse $r): string
    {
        return (string) $r->response()->getBody();
    }

    /**
     * @param array{entity: int, client: int} $s
     */
    private function preview(array $s): string
    {
        return $this->body($this->get('/fragments/invoice-preview?client=' . $s['client'] . '&month=2026-10&entity=' . $s['entity'] . '&issue_date=2026-10-27&delivery_date=2026-10-31'));
    }

    /**
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

    public function testPreviewShowsRateCard(): void
    {
        $this->login();
        $s    = $this->fxPlEurSetup();
        $html = $this->preview($s);

        $this->assertStringContainsString('class="app-card fx-card', $html);
        $this->assertStringContainsString('Kurz NBP: 1 EUR = 4,3951 PLN', $html);
        $this->assertStringContainsString('tab. 211/A/NBP/2026', $html);
        $this->assertStringContainsString('podľa dátumu dodania', $html);
        $this->assertStringContainsString('Spolu v PLN', $html);
        $this->assertStringContainsString('href="/settings/fx"', $html);
        $this->assertDoesNotMatchRegularExpression('/id="inv-issue-btn"[^>]* disabled/', $html);
        $this->assertStringContainsString('NBP, z ', $html);
        $this->assertDoesNotMatchRegularExpression('/(style|onclick)=/', (string) substr($html, (int) strpos($html, 'fx-card'), 2500));
    }

    public function testIssueFromPreviewMakesNoHttpCall(): void
    {
        $this->login();
        $s     = $this->fxPlEurSetup();
        $form  = $this->formFrom($this->preview($s));
        $calls = count($this->http->calls);

        $this->withSession();
        $result = $this->withHeaders(['HX-Request' => 'true'])->call('post', '/invoices/issue', $form + [csrf_token() => csrf_hash()]);

        $result->assertStatus(200);
        $this->assertCount($calls, $this->http->calls);
        $this->assertSame('NBP', $this->owner()->query('SELECT fx_source FROM invoices')->getRow()->fx_source);
    }

    public function testFutureDeliveryShowsNotYetPublishedWithoutRetry(): void
    {
        $this->login();
        $s    = $this->fxPlEurSetup();
        $html = $this->body($this->get('/fragments/invoice-preview?client=' . $s['client'] . '&month=2026-10&entity=' . $s['entity'] . '&issue_date=2026-10-27&delivery_date=2026-11-06'));

        $this->assertStringContainsString('Kurz zatiaľ nie je k dispozícii', $html);
        $this->assertStringContainsString('NBP ho ešte nezverejnil', $html);
        $this->assertStringNotContainsString('fx_retry', $html);
        $this->assertSame([], $this->http->calls);
    }

    public function testOutageShowsWarningAndDisablesIssue(): void
    {
        $this->login();
        $this->http->respond(self::URL, 503, '');
        $s    = $this->fxPlEurSetup();
        $html = $this->preview($s);

        $this->assertStringContainsString('Kurz zatiaľ nie je k dispozícii', $html);
        $this->assertStringContainsString('Kurz NBP sa nepodarilo načítať', $html);
        $this->assertStringContainsString('Spolu v PLN: bude známe po zadaní kurzu.', $html);
        $this->assertMatchesRegularExpression('/id="inv-issue-btn"[^>]* disabled/', $html);
        $this->assertStringNotContainsString('class="fx-total"', $html);
    }

    public function testUnsupportedPairShowsVariantTwo(): void
    {
        $this->login();
        $this->owner()->query("UPDATE fx_country_settings SET source = 'ECB' WHERE country = 'PL'");
        $s    = $this->fxPlEurSetup();
        $html = $this->preview($s);

        $this->assertStringContainsString('Zdroj ECB neposkytuje kurz pre túto dvojicu mien (PLN a EUR)', $html);
        $this->assertStringNotContainsString('skúste načítanie znova', $html);
        $this->assertSame([], $this->http->calls);
    }

    public function testSameCurrencyHasNoCard(): void
    {
        $this->login();
        $s = $this->issueSetup();

        $this->assertStringNotContainsString('fx-card', $this->preview($s));
    }

    public function testIssueWithoutCachedRateIs422(): void
    {
        $this->login();
        $s    = $this->fxPlEurSetup();
        $form = $this->formFrom($this->preview($s));
        $this->owner()->query('DELETE FROM exchange_rate_cache');

        $this->withSession();
        $result = $this->withHeaders(['HX-Request' => 'true'])->call('post', '/invoices/issue', $form + [csrf_token() => csrf_hash()]);

        // token zodpoveda kurzu, bez cache vystavenie odmietne ako fx_missing
        $result->assertStatus(422);
        $this->assertStringContainsString('Pred vystavením je potrebný kurz', $this->body($result));
    }
}
