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
 * Rucne zadanie kurzu v nahlade a vystavenie s rucnym kurzom (Phase 8, CUR-02, CUR-03, D-11, D-12, D-13).
 *
 * @internal
 */
#[Group('feature')]
final class FxManualEntryTest extends AuthTestCase
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
     * @param array<string, string>           $extra
     */
    private function preview(array $s, array $extra = [], string $delivery = '2026-10-31'): TestResponse
    {
        $q = ['client' => $s['client'], 'month' => '2026-10', 'entity' => $s['entity'], 'issue_date' => '2026-10-27', 'delivery_date' => $delivery] + $extra;

        return $this->get('/fragments/invoice-preview?' . http_build_query($q));
    }

    public function testOutageOpensManualEntryWithRetry(): void
    {
        $this->login();
        $this->http->respond(self::URL, 503, '');
        $s    = $this->fxPlEurSetup();
        $html = $this->body($this->preview($s));

        $this->assertMatchesRegularExpression('/<details class="fx-manual"[^>]* open/', $html);
        $this->assertMatchesRegularExpression('/name="fx_rate"/', $html);
        $this->assertStringContainsString('inputmode="decimal"', $html);
        $this->assertStringContainsString('1 EUR =', $html);
        $this->assertStringContainsString('PLN', $html);
        $this->assertMatchesRegularExpression('/type="date" name="fx_date" id="fx-manual-date" value="2026-10-30"/', $html);
        $this->assertStringContainsString('Použiť kurz', $html);
        $this->assertStringContainsString('Skúsiť znova', $html);
        $this->assertStringContainsString('fx_retry', $html);
        $this->assertDoesNotMatchRegularExpression('/(style|onclick|x-data)=[^>]*fx-manual/', $html);
    }

    public function testManualRateIsShownWithBadgeAndHiddenFields(): void
    {
        $this->login();
        $s    = $this->fxPlEurSetup();
        $html = $this->body($this->preview($s, ['fx_mode' => 'manual', 'fx_rate' => '4,3', 'fx_anchor' => '2026-10-31']));

        $this->assertStringContainsString('Ručný kurz', $html);
        $this->assertStringContainsString('Kurz: 1 EUR = 4,3 PLN', $html);
        $this->assertStringContainsString('zadané ručne', $html);
        $this->assertStringContainsString('Stiahnutý kurz bol NBP 4,3951 z ', $html);
        $this->assertMatchesRegularExpression('/id="inv-fx-mode" name="fx_mode" form="inv-issue-form" value="manual"/', $html);
        $this->assertMatchesRegularExpression('/id="inv-fx-rate" name="fx_rate" form="inv-issue-form" value="4.3"/', $html);
        $this->assertStringContainsString('Zahodiť ručný kurz', $html);
        $this->assertStringContainsString('#inv-fx-mode', $html);
    }

    public function testInvalidManualRateIs422WithMessages(): void
    {
        $this->login();
        $s = $this->fxPlEurSetup();

        $r = $this->preview($s, ['fx_mode' => 'manual', 'fx_rate' => '0', 'fx_anchor' => '2026-10-31']);
        $r->assertStatus(422);
        $this->assertStringContainsString('is-invalid', $this->body($r));
        $this->assertStringContainsString('Zadajte kurz ako kladné číslo.', $this->body($r));

        $r = $this->preview($s, ['fx_mode' => 'manual', 'fx_rate' => '4.123456789', 'fx_anchor' => '2026-10-31']);
        $this->assertStringContainsString('Kurz môže mať najviac 8 desatinných miest.', $this->body($r));

        $r = $this->preview($s, ['fx_mode' => 'manual', 'fx_rate' => '4.3', 'fx_date' => '2026-11-15', 'fx_anchor' => '2026-10-31']);
        $r->assertStatus(422);
        $this->assertStringContainsString('Dátum platnosti kurzu nemôže byť v budúcnosti.', $this->body($r));
    }

    public function testIssueWithManualRate(): void
    {
        $this->login();
        $s    = $this->fxPlEurSetup();
        $html = $this->body($this->preview($s, ['fx_mode' => 'manual', 'fx_rate' => '4.3', 'fx_anchor' => '2026-10-31']));
        preg_match_all('/<input type="hidden" name="([a-z_\[\]]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER);
        $form = [];

        foreach ($m as $row) {
            if ($row[1] === 'part_ids[]') {
                $form['part_ids'][] = $row[2];
            } elseif (! in_array($row[1], ['csrf_test_name', csrf_token()], true)) {
                $form[$row[1]] = html_entity_decode($row[2]);
            }
        }

        $form += ['fx_mode' => 'manual', 'fx_rate' => '4.3', 'fx_date' => ''];
        $this->withSession();
        $result = $this->withHeaders(['HX-Request' => 'true'])->call('post', '/invoices/issue', $form + [csrf_token() => csrf_hash()]);

        $result->assertStatus(200);
        $this->assertSame('manual', $this->owner()->query('SELECT fx_source FROM invoices')->getRow()->fx_source);
    }

    public function testIssueWithInvalidManualRateIs422(): void
    {
        $this->login();
        $s    = $this->fxPlEurSetup();
        $html = $this->body($this->preview($s));
        preg_match_all('/<input type="hidden" name="([a-z_\[\]]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER);
        $form = [];

        foreach ($m as $row) {
            if ($row[1] === 'part_ids[]') {
                $form['part_ids'][] = $row[2];
            } elseif (! in_array($row[1], ['csrf_test_name', csrf_token()], true)) {
                $form[$row[1]] = html_entity_decode($row[2]);
            }
        }

        $form += ['fx_mode' => 'manual', 'fx_rate' => 'abc', 'fx_date' => ''];
        $this->withSession();
        $result = $this->withHeaders(['HX-Request' => 'true'])->call('post', '/invoices/issue', $form + [csrf_token() => csrf_hash()]);

        $result->assertStatus(422);
        $this->assertStringContainsString('Ručne zadaný kurz nie je platný', $this->body($result));
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM invoices')->getRow()->c);
    }

    public function testRetryBypassesNegativeCache(): void
    {
        $this->login();
        $this->http->respond(self::URL, 503, '');
        $s = $this->fxPlEurSetup();
        $this->preview($s);
        $this->preview($s);
        $this->assertCount(1, $this->http->calls);

        $this->http->respond(self::URL, 200, FakeRateHttpClient::fixture('nbp_eur_range.json'));
        $html = $this->body($this->preview($s, ['fx_retry' => '1']));

        $this->assertCount(2, $this->http->calls);
        $this->assertStringContainsString('Kurz NBP: 1 EUR = 4,3951 PLN', $html);
        $this->assertStringNotContainsString('name="fx_retry"', $html);
    }

    public function testChangedDateDropsManualRate(): void
    {
        $this->login();
        $s = $this->fxPlEurSetup();
        $this->http->respond('https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-04/2026-10-14/?format=json', 200, '{"table":"A","currency":"euro","code":"EUR","rates":[{"no":"197/A/NBP/2026","effectiveDate":"2026-10-13","mid":4.3500}]}');
        $html = $this->body($this->preview($s, ['fx_mode' => 'manual', 'fx_rate' => '4.3', 'fx_anchor' => '2026-10-31'], '2026-10-15'));

        $this->assertStringContainsString('Dátum sa zmenil, kurz bol načítaný znova.', $html);
        $this->assertStringNotContainsString('Ručný kurz', $html);
        $this->assertStringContainsString('Kurz NBP: 1 EUR = 4,3500 PLN', $html);
    }

    public function testUnsupportedPairHasNoRetry(): void
    {
        $this->login();
        $this->owner()->query("UPDATE fx_country_settings SET source = 'ECB' WHERE country = 'PL'");
        $s    = $this->fxPlEurSetup();
        $html = $this->body($this->preview($s));

        $this->assertStringContainsString('fx-manual', $html);
        $this->assertStringNotContainsString('Skúsiť znova', $html);
    }

    public function testConfirmSheetShowsManualBadge(): void
    {
        $this->login();
        $s     = $this->fxPlEurSetup();
        $html  = $this->body($this->preview($s, ['fx_mode' => 'manual', 'fx_rate' => '4.3', 'fx_anchor' => '2026-10-31']));
        $sheet = substr($html, (int) strpos($html, 'id="inv-confirm"'));

        $this->assertStringContainsString('Ručný kurz', $sheet);
        $this->assertStringContainsString('1 EUR = 4,3 PLN', $sheet);
    }
}
