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
        $csv = "OBS_VALUE,TITLE_COMPL,TIME_PERIOD,KEY\n"
            . "4.1000,\"a, b, c\",2026-10-28,K\n"
            . "4.2500,\"ECB reference exchange rate, Polish zloty/Euro, 2.15 pm\",2026-10-29,K\n";
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 200, $csv);

        $q = (new EcbRateProvider($http))->latestOnOrBefore('PLN', '2026-10-29');

        $this->assertSame('4.2500', $q->rate);
        $this->assertSame('2026-10-29', $q->validFor);
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
                $this->assertTrue(true);
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
