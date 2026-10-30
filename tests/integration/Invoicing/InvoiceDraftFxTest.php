<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Fx\FxService;
use App\Libraries\Invoicing\InvoiceDraftService;
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
 * Nahlad stahuje kurz (D-07), meni token pri zmene datumu (D-08) a pri vypadku ostava funkcny (D-12).
 *
 * @internal
 */
#[Group('integration')]
final class InvoiceDraftFxTest extends PgTestCase
{
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private const URL_1030 = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-20/2026-10-30/?format=json';
    private const URL_1014 = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-04/2026-10-14/?format=json';

    private FakeRateHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-30T10:00:00+01:00'));
        Services::injectMock('cache', new MockCache());
        $this->http = new FakeRateHttpClient();
        $this->http->respond(self::URL_1030, 200, FakeRateHttpClient::fixture('nbp_eur_range.json'));
        $this->http->respond(self::URL_1014, 200, '{"table":"A","currency":"euro","code":"EUR","rates":[{"no":"197/A/NBP/2026","effectiveDate":"2026-10-13","mid":4.3500}]}');
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

    public function testFutureBoundShowsNotYetPublishedWithoutHttp(): void
    {
        $s = $this->fxPlEurSetup();
        $d = $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-11-05', '2026-11-15');

        $this->assertSame('unavailable', $d->fx['state']);
        $this->assertTrue($d->fx['future_bound']);
        $this->assertFalse($d->canIssue);
        $this->assertSame([], $this->http->calls);
    }

    public function testOkStateCachesAndTokenMatchesIssuer(): void
    {
        $s = $this->fxPlEurSetup();
        $d = $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-31', '2026-11-10');

        $this->assertSame('ok', $d->fx['state']);
        $this->assertSame('NBP', $d->fx['source']);
        $this->assertSame('2026-10-30', $d->fx['bound']);
        $this->assertSame('delivery_date', $d->fx['date_basis']);
        $this->assertSame('4.3951', $d->fx['quote']['rate_display']);
        $this->assertSame('4746.71', $d->fx['quote']['total']);
        $this->assertNotNull($d->previewToken);
        $this->assertTrue($d->canIssue);
        $this->assertSame([self::URL_1030], $this->http->calls);

        $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-31', '2026-11-10');
        $this->assertSame([self::URL_1030], $this->http->calls);

        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['previewToken' => $d->previewToken]));
        $this->assertNotSame('', $issued->number);
        $this->assertSame([self::URL_1030], $this->http->calls);
    }

    public function testDeliveryDateChangeLooksUpNewRateAndChangesToken(): void
    {
        $s = $this->fxPlEurSetup();
        $a = $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-31', '2026-11-10');
        $b = $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-15', '2026-11-10');

        $this->assertSame(self::URL_1014, $this->http->calls[1]);
        $this->assertNotSame($a->previewToken, $b->previewToken);
    }

    public function testIssueDateBasisUsesIssueDate(): void
    {
        $this->owner()->query("UPDATE fx_country_settings SET date_basis = 'issue_date' WHERE country = 'PL'");
        $s = $this->fxPlEurSetup();
        $d = $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-31', '2026-11-10');

        $this->assertSame('issue_date', $d->fx['date_basis']);
        $this->assertSame('2026-10-26', $d->fx['bound']);
    }

    public function testOutageKeepsPreviewButBlocksIssue(): void
    {
        $this->http->respond(self::URL_1030, 503, '');
        $s = $this->fxPlEurSetup();
        $d = $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-31', '2026-11-10');

        $this->assertSame('unavailable', $d->fx['state']);
        $this->assertNull($d->previewToken);
        $this->assertFalse($d->canIssue);
        $this->assertSame([], $d->errors);
        $this->assertSame(1, $d->lineCount);
        $this->assertSame('1080.00', $d->total);
        $this->assertNotNull($d->snapshot);
    }

    public function testRetryRepeatsHttpCallAfterFailure(): void
    {
        $this->http->respond(self::URL_1030, 503, '');
        $s = $this->fxPlEurSetup();
        $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-31', '2026-11-10');
        $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-31', '2026-11-10');
        $this->assertSame([self::URL_1030], $this->http->calls);

        $this->http->respond(self::URL_1030, 200, FakeRateHttpClient::fixture('nbp_eur_range.json'));
        $d = $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-31', '2026-11-10', null, null, false, true);

        $this->assertCount(2, $this->http->calls);
        $this->assertSame('ok', $d->fx['state']);
    }

    public function testUnsupportedSourceMakesNoHttpCall(): void
    {
        $this->owner()->query("UPDATE fx_country_settings SET source = 'ECB' WHERE country = 'PL'");
        $s = $this->fxPlEurSetup();
        $d = $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-31', '2026-11-10');

        $this->assertSame('unsupported', $d->fx['state']);
        $this->assertFalse($d->canIssue);
        $this->assertSame([], $this->http->calls);
    }

    public function testOverflowGoesToErrors(): void
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-29', '9999999999.0000', 1, '211/A/NBP/2026');
        $d = $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-31', '2026-11-10');

        $this->assertSame('overflow', $d->fx['state']);
        $this->assertSame('fx_overflow', $d->errors[0]['reason']);
        $this->assertFalse($d->canIssue);
    }

    public function testSameCurrencyHasNoFx(): void
    {
        $s = $this->issueSetup();
        $d = $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-27', '2026-10-31', '2026-11-10');

        $this->assertNull($d->fx);
        $this->assertTrue($d->canIssue);
        $this->assertSame([], $this->http->calls);
    }
}
