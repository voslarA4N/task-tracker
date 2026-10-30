<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Fx\FxInput;
use App\Libraries\Fx\FxService;
use App\Libraries\Invoicing\InvoiceDraftService;
use App\Libraries\Invoicing\IssueRejected;
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
 * Rucny kurz v nahlade a pri vystaveni (CUR-02, CUR-03, D-08, D-11, D-12, D-13).
 *
 * @internal
 */
#[Group('integration')]
final class InvoiceManualFxTest extends PgTestCase
{
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private const URL_1030 = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-20/2026-10-30/?format=json';

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

    private function service(): InvoiceDraftService
    {
        return new InvoiceDraftService($this->rw(), new FxService($this->rw(), $this->http));
    }

    /**
     * @param array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int} $s
     */
    private function draft(array $s, ?FxInput $in, string $delivery = '2026-10-31'): \App\Libraries\Invoicing\Draft
    {
        return $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-30', $delivery, '2026-11-10', null, null, false, false, $in);
    }

    private function manual(string $rate, string $date = '', string $anchor = '2026-10-31'): FxInput
    {
        return FxInput::fromArray(['fx_mode' => 'manual', 'fx_rate' => $rate, 'fx_date' => $date, 'fx_anchor' => $anchor], '2026-10-30');
    }

    private function written(): int
    {
        return (int) $this->owner()->query('SELECT (SELECT count(*) FROM invoices) + (SELECT count(*) FROM invoice_counters) AS c')->getRow()->c;
    }

    public function testManualOverridesFetchedRateInDraft(): void
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');

        $d = $this->draft($s, $this->manual('4.3000'));

        $this->assertSame('manual', $d->fx['state']);
        $this->assertSame('manual', $d->fx['quote']['source']);
        $this->assertSame('2026-10-30', $d->fx['quote']['valid_for']);
        $this->assertSame('4.4090', $d->fx['quote']['fetched']['rate']);
        $this->assertSame('4644.00', $d->fx['quote']['total']);
        $this->assertNotNull($d->previewToken);
        $this->assertSame('manual', $d->fx['fields']['fx_mode']);
        $this->assertSame('4.3000', $d->fx['fields']['fx_rate']);
    }

    public function testIssueWithManualRateStoresFetchedAndAudit(): void
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');
        $in = $this->manual('4.3000');
        $d  = $this->draft($s, $in);

        $cmd    = $this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-30', 'previewToken' => $d->previewToken, 'fx' => $in]);
        $issued = $this->issuer()->issue($cmd);

        $row = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $issued->id])->getRowArray();
        $this->assertSame('manual', $row['fx_source']);
        $this->assertSame('4.30000000', $row['fx_rate']);
        $this->assertNull($row['fx_table_no']);
        $this->assertSame('4644.00', $row['total_entity_ccy']);

        $snap = json_decode((string) $row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($snap['fx']['manual']);
        $this->assertSame('NBP', $snap['fx']['fetched']['source']);

        $audit = json_decode((string) $this->owner()->query("SELECT after_data FROM audit_log WHERE entity_type = 'invoice' AND entity_id = :i: AND action = 'issue'", ['i' => $issued->id])->getRow()->after_data, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($audit['fx_manual']);
        $this->assertSame('4.4090', $audit['fx_fetched_rate']);
        $this->assertSame([], $this->http->calls);
    }

    public function testOutageStillAllowsManualRate(): void
    {
        $this->http->respond(self::URL_1030, 503, '');
        $s = $this->fxPlEurSetup();

        $this->assertSame('unavailable', $this->draft($s, FxInput::auto())->fx['state']);

        $in = $this->manual('4.3');
        $d  = $this->draft($s, $in);
        $this->assertSame('manual', $d->fx['state']);
        $this->assertNull($d->fx['quote']['fetched']);
        $this->assertNotNull($d->previewToken);

        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-30', 'previewToken' => $d->previewToken, 'fx' => $in]));
        $this->assertNotSame('', $issued->number);
    }

    public function testAnchorChangeDropsManualRate(): void
    {
        $s = $this->fxPlEurSetup();
        $this->http->respond('https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-04/2026-10-14/?format=json', 200, '{"table":"A","currency":"euro","code":"EUR","rates":[{"no":"197/A/NBP/2026","effectiveDate":"2026-10-13","mid":4.3500}]}');

        $d = $this->draft($s, $this->manual('4.3', '', '2026-10-31'), '2026-10-15');

        $this->assertSame('ok', $d->fx['state']);
        $this->assertTrue($d->fx['anchor_changed']);
        $this->assertSame('', $d->fx['fields']['fx_mode']);
    }

    public function testInvalidManualRateBlocksToken(): void
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');

        $d = $this->draft($s, $this->manual('0'));

        $this->assertSame('invalid', $d->fx['state']);
        $this->assertSame('invalid', $d->fx['errors']['fx_rate']);
        $this->assertNull($d->previewToken);
        $this->assertFalse($d->canIssue);
        $this->assertSame('0', $d->fx['fields']['fx_rate']);
    }

    public function testIssueWithInvalidManualRateIsRejected(): void
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');
        $cmd = $this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-30', 'fx' => $this->manual('abc')]);

        try {
            $this->issuer()->issue($cmd);
            $this->fail('Expected IssueRejected.');
        } catch (IssueRejected $e) {
            $this->assertSame('fx_invalid', $e->reason);
        }

        $this->assertSame(0, $this->written());
    }

    public function testManualInputIgnoredForSameCurrency(): void
    {
        $s      = $this->issueSetup();
        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['fx' => $this->manual('4.3')]));

        $row = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $issued->id])->getRowArray();
        $this->assertNull($row['fx_rate']);
        $this->assertNull($row['fx_source']);
    }

    public function testUnsupportedPairAllowsManualRate(): void
    {
        $this->owner()->query("UPDATE fx_country_settings SET source = 'ECB' WHERE country = 'PL'");
        $s = $this->fxPlEurSetup();

        $d = $this->draft($s, $this->manual('4.3'));

        $this->assertSame('manual', $d->fx['state']);
        $this->assertSame('multiply', $d->fx['direction']);
        $this->assertSame('multiply', $d->fx['quote']['direction']);
        $this->assertNotNull($d->previewToken);
        $this->assertSame([], $this->http->calls);
    }
}
