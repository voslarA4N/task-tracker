<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Fx\FxInput;
use App\Libraries\Fx\FxService;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\InvoiceDraftService;
use App\Libraries\Invoicing\InvoiceQueries;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\Mock\MockCache;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeRateHttpClient;
use Tests\Support\FxFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * Vystavena faktura s kurzom sa nezmeni po zmene cache ani nastaveni (CUR-02, D-07, D-14).
 *
 * @internal
 */
#[Group('integration')]
final class InvoiceFxImmutableTest extends PgTestCase
{
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-30T10:00:00+01:00'));
        Services::injectMock('cache', new MockCache());
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->restoreFxSettings();
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    public function testCacheAndSettingsChangesDoNotTouchIssuedInvoice(): void
    {
        $http = new FakeRateHttpClient();
        Services::injectMock('rateHttpClient', $http);
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');
        $in = FxInput::auto();
        $d  = (new InvoiceDraftService($this->rw(), new FxService($this->rw(), $http)))
            ->draft($s['client'], '2026-10', $s['entity'], '2026-10-30', '2026-10-31', '2026-11-10', null, null, false, false, $in);
        $id = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-30', 'previewToken' => $d->previewToken, 'fx' => $in]))->id;

        $before = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $id])->getRowArray();
        $doc    = (new DocumentStore($this->rw()))->read($id);
        $this->assertNotNull($doc);
        $this->assertSame('4761.72', $before['total_entity_ccy']);

        $this->owner()->query("UPDATE exchange_rate_cache SET rate = 9.9999, rate_published = 9.9999 WHERE source = 'NBP'");
        $this->owner()->query("UPDATE fx_country_settings SET source = 'ECB', date_basis = 'issue_date' WHERE country = 'PL'");

        // nove sťahovanie kurzu s iným nastavením nesmie zasiahnuť vystavený doklad
        $this->owner()->query("UPDATE fx_country_settings SET source = 'NBP', date_basis = 'issue_date' WHERE country = 'PL'");
        $http->respond('https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-19/2026-10-29/?format=json', 200, '{"table":"A","currency":"euro","code":"EUR","rates":[{"no":"210/A/NBP/2026","effectiveDate":"2026-10-29","mid":4.5000}]}');
        $fx  = new FxService($this->rw(), $http);
        $ctx = $fx->context('PL', 'PLN', 'EUR', '2026-10-30', '2026-10-31');
        $this->assertNotNull($ctx);

        try {
            $fx->resolve($ctx);
        } catch (\Throwable) {
            // vysledok nie je podstatny, iba ze doklad zostane
        }

        $after = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $id])->getRowArray();
        $this->assertSame($before, $after);
        $docAfter = (new DocumentStore($this->rw()))->read($id);
        $this->assertNotNull($docAfter);
        $this->assertSame($doc->sha256, $docAfter->sha256);
        $this->assertSame($doc->bytes, $docAfter->bytes);

        $detail = (new InvoiceQueries($this->rw()))->detail($id);
        $this->assertNotNull($detail);
        $this->assertSame('4.40900000', $detail['fx_rate']);
        $this->assertSame('4761.72', $detail['total_entity_ccy']);
        $this->assertSame('NBP', $detail['fx_source']);
        $this->assertSame('4.4090', $detail['snapshot']['fx']['rate_display']);
    }

    public function testViewsDoNotReadCacheOrSettings(): void
    {
        foreach (['app/Libraries/Invoicing/InvoiceQueries.php', 'app/Views/invoices/show.php', 'app/Views/invoices/_list.php'] as $f) {
            $src = (string) file_get_contents(ROOTPATH . $f);
            $this->assertStringNotContainsString('exchange_rate_cache', $src, $f);
            $this->assertStringNotContainsString('fx_country_settings', $src, $f);
        }
    }
}
