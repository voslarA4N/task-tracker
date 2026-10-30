<?php

declare(strict_types=1);

namespace Tests\Integration\Fx;

use App\Libraries\Fx\FxContext;
use App\Libraries\Fx\FxService;
use App\Libraries\Fx\RateNotFound;
use App\Libraries\Fx\RateUnavailable;
use App\Libraries\Fx\UnsupportedPair;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\Mock\MockCache;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeRateHttpClient;
use Tests\Support\FxFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class FxServiceCacheTest extends PgTestCase
{
    use MasterFixtures;
    use IssueFixtures;
    use FxFixtures;

    private const NBP_URL = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-20/2026-10-30/?format=json';

    private FakeRateHttpClient $http;
    private FxService $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        Services::injectMock('clock', new FrozenClock('2026-10-30T10:00:00+01:00'));
        Services::injectMock('cache', new MockCache());
        $this->http = new FakeRateHttpClient();
        $this->http->respond(self::NBP_URL, 200, FakeRateHttpClient::fixture('nbp_eur_range.json'));
        $this->fx = new FxService($this->rw(), $this->http);
    }

    protected function tearDown(): void
    {
        $this->restoreFxSettings();
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function calls(): int
    {
        return count($this->http->calls);
    }

    private function plCtx(string $issue = '2026-10-27', string $delivery = '2026-10-31'): FxContext
    {
        $ctx = $this->fx->context('PL', 'PLN', 'EUR', $issue, $delivery);
        $this->assertNotNull($ctx);

        return $ctx;
    }

    public function testContext(): void
    {
        $ctx = $this->plCtx();
        $this->assertSame('NBP', $ctx->source);
        $this->assertSame('2026-10-31', $ctx->anchor);
        $this->assertSame('2026-10-30', $ctx->bound);
        $this->assertTrue($ctx->supported);
        $this->assertSame('multiply', $ctx->direction);
        $this->assertNull($this->fx->context('PL', 'PLN', 'PLN', '2026-10-27', '2026-10-31'));
    }

    public function testResolveCachesAndCachedMatches(): void
    {
        $ctx = $this->plCtx();
        $q1  = $this->fx->resolve($ctx);

        $this->assertSame([self::NBP_URL], $this->http->calls);
        $row = $this->owner()->query('SELECT * FROM exchange_rate_cache')->getResultArray();
        $this->assertCount(1, $row);
        $this->assertSame('NBP', $row[0]['source']);
        $this->assertSame('2026-10-30', $row[0]['bound_date']);
        $this->assertSame('4.3951', $row[0]['rate_published']);
        $this->assertSame('211/A/NBP/2026', $q1->tableNo);

        $q2 = $this->fx->resolve($ctx);
        $this->assertSame(1, $this->calls());
        $this->assertSame(json_encode($q1->core()), json_encode($q2->core()));
        $this->assertSame(json_encode($q1->core()), json_encode($this->fx->cached($ctx)?->core()));
    }

    public function testCachedNeverCallsNetwork(): void
    {
        $this->assertNull($this->fx->cached($this->plCtx()));
        $this->assertSame([], $this->http->calls);
    }

    private const NBP_URL_1020  = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-10/2026-10-20/?format=json';
    private const NBP_BODY_1020 = '{"rates":[{"no":"203/A/NBP/2026","effectiveDate":"2026-10-19","mid":4.3702},{"no":"204/A/NBP/2026","effectiveDate":"2026-10-20","mid":4.3716}]}';

    private function ctx1020(): FxContext
    {
        $ctx = $this->fx->context('PL', 'PLN', 'EUR', '2026-10-15', '2026-10-21');
        $this->assertNotNull($ctx);
        $this->assertSame('2026-10-20', $ctx->bound);

        return $ctx;
    }

    private function storedRate1020(): string
    {
        return (string) $this->owner()->query("SELECT rate_published FROM exchange_rate_cache WHERE bound_date = '2026-10-20'")->getRow()->rate_published;
    }

    public function testFinalRowNeverOverwritten(): void
    {
        // stiahnuty den po bound (Europe/Bratislava): kurz za bound uz bol zverejneny, riadok je finalny
        $this->seedRate('NBP', 'EUR', '2026-10-20', '2026-10-20', '4.1000', 1, 'X', '2026-10-21 10:00:00+02');
        $ctx = $this->ctx1020();
        $this->assertSame('4.1000', $this->fx->resolve($ctx)->rate);
        $this->assertSame('4.1000', $this->fx->resolve($ctx, true)->rate);
        $this->assertSame([], $this->http->calls);
        $this->assertSame('4.1000', $this->storedRate1020());
    }

    public function testRowFetchedBeforePublicationIsRefreshedAfterBound(): void
    {
        // CR-02: nahlad v den bound pred zverejnenim vratil kurz z predosleho dna; po bound sa nesmie zmrazit
        $this->seedRate('NBP', 'EUR', '2026-10-20', '2026-10-19', '4.3702', 1, '203/A/NBP/2026', '2026-10-20 09:00:00+02');
        $this->http->respond(self::NBP_URL_1020, 200, self::NBP_BODY_1020);
        $ctx = $this->ctx1020();

        $q = $this->fx->resolve($ctx);
        $this->assertSame([self::NBP_URL_1020], $this->http->calls);
        $this->assertSame('4.3716', $q->rate);
        $this->assertSame('2026-10-20', $q->validFor);
        $this->assertSame('204/A/NBP/2026', $q->tableNo);
        $this->assertSame('4.3716', $this->storedRate1020());

        // obnoveny riadok je teraz finalny: dalsie hladanie ani retry siet nevolaju
        $this->fx->resolve($ctx, true);
        $this->assertSame(1, $this->calls());
    }

    public function testFinalityUsesLocalCalendarDayOfFetch(): void
    {
        // 2026-10-20 21:30 UTC = 23:30 v Bratislave, teda este v den bound: nie je finalny
        $this->seedRate('NBP', 'EUR', '2026-10-20', '2026-10-19', '4.3702', 1, '203/A/NBP/2026', '2026-10-20 21:30:00+00');
        $this->http->respond(self::NBP_URL_1020, 200, self::NBP_BODY_1020);
        $this->assertSame('4.3716', $this->fx->resolve($this->ctx1020())->rate);
        $this->assertSame(1, $this->calls());
    }

    public function testFinalityAfterLocalMidnight(): void
    {
        // 2026-10-20 22:30 UTC = 2026-10-21 00:30 v Bratislave: finalny
        $this->seedRate('NBP', 'EUR', '2026-10-20', '2026-10-20', '4.3716', 1, '204/A/NBP/2026', '2026-10-20 22:30:00+00');
        $this->assertSame('4.3716', $this->fx->resolve($this->ctx1020(), true)->rate);
        $this->assertSame([], $this->http->calls);
    }

    public function testOutageKeepsPrePublicationRowAsFallback(): void
    {
        $this->seedRate('NBP', 'EUR', '2026-10-20', '2026-10-19', '4.3702', 1, '203/A/NBP/2026', '2026-10-20 09:00:00+02');
        $this->http->respond(self::NBP_URL_1020, 503, 'down');
        $this->assertSame('4.3702', $this->fx->resolve($this->ctx1020())->rate);
        $this->assertSame(1, $this->calls());
    }

    public function testNonFinalRefreshesAfterFifteenMinutes(): void
    {
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-29', '4.0000', 1, 'OLD', '2026-10-30 09:30:00+01');
        $ctx = $this->plCtx();
        $q   = $this->fx->resolve($ctx);
        $this->assertSame(1, $this->calls());
        $this->assertSame('4.3951', $q->rate);

        $this->fx->resolve($ctx);
        $this->assertSame(1, $this->calls());
    }

    public function testYoungNonFinalRowNoHttp(): void
    {
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-29', '4.0000');
        $this->assertSame('4.0000', $this->fx->resolve($this->plCtx())->rate);
        $this->assertSame([], $this->http->calls);
    }

    public function testOutageNegativeCacheAndRetry(): void
    {
        $this->http->respond(self::NBP_URL, 503, 'down');
        $ctx = $this->plCtx();

        try {
            $this->fx->resolve($ctx);
            $this->fail('Expected RateUnavailable');
        } catch (RateUnavailable) {
            $this->assertSame(1, $this->calls());
        }

        try {
            $this->fx->resolve($ctx);
            $this->fail('Expected RateUnavailable');
        } catch (RateUnavailable) {
            $this->assertSame(1, $this->calls());
        }

        try {
            $this->fx->resolve($ctx, true);
            $this->fail('Expected RateUnavailable');
        } catch (RateUnavailable) {
            $this->assertSame(2, $this->calls());
        }
    }

    public function testOutageFallsBackToStaleNonFinalRow(): void
    {
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-29', '4.0000', 1, 'OLD', '2026-10-30 08:00:00+01');
        $this->http->respond(self::NBP_URL, 503, 'down');

        $this->assertSame('4.0000', $this->fx->resolve($this->plCtx())->rate);
    }

    public function testNotFound(): void
    {
        $this->http->respond(self::NBP_URL, 400, 'Brak danych');
        $this->expectException(RateNotFound::class);
        $this->fx->resolve($this->plCtx());
    }

    public function testUnsupportedPairWithoutHttp(): void
    {
        $this->owner()->query("UPDATE fx_country_settings SET source = 'ECB' WHERE country = 'PL'");
        $ctx = $this->plCtx();
        $this->assertFalse($ctx->supported);
        $this->assertNull($this->fx->cached($ctx));

        try {
            $this->fx->resolve($ctx);
            $this->fail('Expected UnsupportedPair');
        } catch (UnsupportedPair) {
            $this->assertSame([], $this->http->calls);
        }
    }

    public function testIssueDateBasisChangesBoundAndUrl(): void
    {
        $this->owner()->query("UPDATE fx_country_settings SET date_basis = 'issue_date' WHERE country = 'PL'");
        $ctx = $this->plCtx();
        $this->assertSame('2026-10-26', $ctx->bound);

        $url = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-16/2026-10-26/?format=json';
        $this->http->respond($url, 200, '{"rates":[{"no":"206/A/NBP/2026","effectiveDate":"2026-10-26","mid":4.4026}]}');
        $this->fx->resolve($ctx);
        $this->assertSame([$url], $this->http->calls);
    }

    public function testCzechEntityUsesCnb(): void
    {
        $ctx = $this->fx->context('CZ', 'CZK', 'EUR', '2026-10-27', '2026-10-28');
        $this->assertNotNull($ctx);
        $this->assertSame('2026-10-28', $ctx->bound);

        $url = 'https://api.cnb.cz/cnbapi/exrates/daily?date=2026-10-28&lang=EN';
        $this->http->respond($url, 200, FakeRateHttpClient::fixture('cnb_daily.json'));

        try {
            $this->fx->resolve($ctx);
        } catch (RateNotFound | RateUnavailable) {
            // obsah fixtury nie je predmetom testu, iba volana URL
        }

        $this->assertSame($url, $this->http->calls[0]);
    }
}
