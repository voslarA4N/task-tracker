<?php

declare(strict_types=1);

namespace Tests\Unit\Fx;

use App\Libraries\Fx\EcbRateProvider;
use App\Libraries\Fx\RateNotFound;
use App\Libraries\Fx\RateProviders;
use App\Libraries\Fx\RateUnavailable;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FakeRateHttpClient;

/**
 * @internal
 */
final class EcbRateProviderTest extends CIUnitTestCase
{
    private const URL = 'https://data-api.ecb.europa.eu/service/data/EXR/D.PLN.EUR.SP00.A?startPeriod=2026-10-19&endPeriod=2026-10-29&format=csvdata';

    public function testLastRowDivide(): void
    {
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 200, FakeRateHttpClient::fixture('ecb_pln.csv'));

        $q = (new EcbRateProvider($http))->latestOnOrBefore('PLN', '2026-10-29');

        $this->assertSame([self::URL], $http->calls);
        $this->assertSame('ECB', $q->source);
        $this->assertSame('4.3938', $q->rate);
        $this->assertSame('2026-10-29', $q->validFor);
        $this->assertNull($q->tableNo);
        $this->assertSame('divide', $q->direction);
        $this->assertSame(1, $q->amount);
    }

    public function testColumnsByHeaderNameAndQuotedCommas(): void
    {
        $csv = "OBS_VALUE,TITLE_COMPL,TIME_PERIOD,KEY,CURRENCY\n"
            . "4.1000,\"a, b, c\",2026-10-28,K,PLN\n"
            . "4.2500,\"ECB reference exchange rate, Polish zloty/Euro, 2.15 pm\",2026-10-29,K,PLN\n";
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 200, $csv);

        $q = (new EcbRateProvider($http))->latestOnOrBefore('PLN', '2026-10-29');

        $this->assertSame('4.2500', $q->rate);
        $this->assertSame('2026-10-29', $q->validFor);
    }

    public function testPicksLatestRowOnOrBeforeBoundRegardlessOfOrder(): void
    {
        // WR-04: neusporiadane riadky, riadok po bound a riadok inej meny sa ignoruju
        $csv = "TIME_PERIOD,OBS_VALUE,CURRENCY\n"
            . "2026-10-28,4.2800,PLN\n"
            . "2026-10-30,9.9999,PLN\n"
            . "2026-10-29,4.2900,CZK\n"
            . "2026-10-27,4.2700,PLN\n";
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 200, $csv);

        $q = (new EcbRateProvider($http))->latestOnOrBefore('PLN', '2026-10-29');

        $this->assertSame('4.2800', $q->rate);
        $this->assertSame('2026-10-28', $q->validFor);
    }

    public function testOnlyRowsAfterBoundOrOtherCurrencyAreNotFound(): void
    {
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 200, "TIME_PERIOD,OBS_VALUE,CURRENCY\n2026-10-30,4.3,PLN\n2026-10-29,4.3,CZK\n");

        $this->expectException(RateNotFound::class);
        (new EcbRateProvider($http))->latestOnOrBefore('PLN', '2026-10-29');
    }

    public function testMissingCurrencyColumnOrBadDateIsParseError(): void
    {
        foreach (["TIME_PERIOD,OBS_VALUE\n2026-10-29,4.3\n", "TIME_PERIOD,OBS_VALUE,CURRENCY\n29.10.2026,4.3,PLN\n"] as $body) {
            $http = new FakeRateHttpClient();
            $http->respond(self::URL, 200, $body);

            try {
                (new EcbRateProvider($http))->latestOnOrBefore('PLN', '2026-10-29');
                $this->fail('Expected RateUnavailable');
            } catch (RateUnavailable $e) {
                $this->assertSame('parse_error', $e->reason);
            }
        }
    }

    public function testEmptyBodyAndHeaderOnlyAreNotFound(): void
    {
        foreach (['', "KEY,TIME_PERIOD,OBS_VALUE\n"] as $body) {
            $http = new FakeRateHttpClient();
            $http->respond(self::URL, 200, $body);

            try {
                (new EcbRateProvider($http))->latestOnOrBefore('PLN', '2026-10-29');
                $this->fail('Expected RateNotFound');
            } catch (RateNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testServerErrorIsUnavailable(): void
    {
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 503, 'x');

        try {
            (new EcbRateProvider($http))->latestOnOrBefore('PLN', '2026-10-29');
            $this->fail('Expected RateUnavailable');
        } catch (RateUnavailable $e) {
            $this->assertSame('http_status', $e->reason);
        }
    }

    public function testRegistry(): void
    {
        $p = RateProviders::for('ECB', new FakeRateHttpClient());

        $this->assertInstanceOf(EcbRateProvider::class, $p);
        $this->assertSame('EUR', $p->homeCurrency());
        $this->assertSame('divide', $p->direction());
    }
}
