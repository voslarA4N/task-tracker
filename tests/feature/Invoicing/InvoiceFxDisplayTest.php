<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Fx\FxInput;
use App\Libraries\Fx\FxService;
use App\Libraries\Invoicing\InvoiceDraftService;
use App\Libraries\Invoicing\InvoiceQueries;
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
 * Detail a zoznam faktur v cudzej mene: druha suma a kurz zo snapshotu (CUR-02, D-14, D-11).
 *
 * @internal
 */
#[Group('feature')]
final class InvoiceFxDisplayTest extends AuthTestCase
{
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private FakeRateHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-30T10:00:00+01:00'));
        Services::injectMock('cache', new MockCache());
        $this->http = new FakeRateHttpClient();
        Services::injectMock('rateHttpClient', $this->http);
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

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function issueFx(?FxInput $in = null): int
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');
        $in ??= FxInput::auto();
        $d = (new InvoiceDraftService($this->rw(), new FxService($this->rw(), $this->http)))
            ->draft($s['client'], '2026-10', $s['entity'], '2026-10-30', '2026-10-31', '2026-11-10', null, null, false, false, $in);

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-30', 'previewToken' => $d->previewToken, 'fx' => $in]))->id;
    }

    public function testDetailShowsSecondTotalAndRateRows(): void
    {
        $this->login();
        $id   = $this->issueFx();
        $html = $this->body($this->get('/invoices/' . $id));

        $this->assertSame(1, substr_count($html, 'Spolu v PLN'));
        $this->assertStringContainsString('class="fx-sub"', $html);
        $this->assertMatchesRegularExpression('#Spolu v PLN\s*4\D?761[.,]72#u', $html);
        $this->assertStringContainsString('1 EUR = 4,4090 PLN', $html);
        $this->assertStringContainsString('NBP, tab. 209/A/NBP/2026', $html);
        $this->assertStringContainsString('podľa dátumu dodania', $html);
        $this->assertStringNotContainsString('Ručný kurz', $html);
    }

    public function testManualRateShowsBadgeAndOriginal(): void
    {
        $this->login();
        $id   = $this->issueFx(FxInput::fromArray(['fx_mode' => 'manual', 'fx_rate' => '4.3000', 'fx_date' => '', 'fx_anchor' => '2026-10-31'], '2026-10-30'));
        $html = $this->body($this->get('/invoices/' . $id));

        $this->assertStringContainsString('Ručný kurz', $html);
        $this->assertStringContainsString('zadané ručne', $html);
        $this->assertStringContainsString('Pôvodný kurz NBP: 1 EUR = 4,4090 PLN z ', $html);
        $this->assertStringContainsString('1 EUR = 4,3000 PLN', $html);
    }

    public function testListShowsSecondTotalOnlyWithRate(): void
    {
        $this->login();
        $this->issueFx();
        $s = $this->issueSetup();
        $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
        $html = $this->body($this->get('/invoices'));

        $this->assertSame(1, substr_count($html, 'class="fx-sub w-100"'));
        $this->assertMatchesRegularExpression('#fx-sub w-100">4\D?761[.,]72#u', $html);
    }

    public function testEntityCurrencyComesFromSnapshot(): void
    {
        // WR-05: mena subjektu pri druhom sucte je z kopie udajov dokladu, nie zo zivej tabulky entities
        $id = $this->issueFx();
        $q  = new InvoiceQueries($this->rw());

        $row = array_values(array_filter($q->list()['rows'], static fn (array $r): bool => $r['id'] === $id))[0];
        $this->assertSame('PLN', $row['entity_currency']);
        $detail = $q->detail($id) ?? [];
        $this->assertArrayNotHasKey('entity_currency', $detail);
        $this->assertSame('PLN', $detail['snapshot']['fx']['entity_currency'] ?? null);
    }
}
