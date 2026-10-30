<?php

declare(strict_types=1);

namespace Tests\Unit\Fx;

use App\Libraries\Fx\CnbRateProvider;
use App\Libraries\Fx\RateNotFound;
use App\Libraries\Fx\RateProviders;
use App\Libraries\Fx\RateUnavailable;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use Tests\Support\FakeRateHttpClient;

/**
 * @internal
 */
final class CnbRateProviderTest extends CIUnitTestCase
{
    private const JSON = 'https://api.cnb.cz/cnbapi/exrates/daily?date=2026-10-31&lang=EN';
    private const TXT  = 'https://www.cnb.cz/en/financial-markets/foreign-exchange-market/central-bank-exchange-rate-fixing/central-bank-exchange-rate-fixing/daily.txt?date=31.10.2026';

    private function http(): FakeRateHttpClient
    {
        $http = new FakeRateHttpClient();
        $http->respond(self::JSON, 200, FakeRateHttpClient::fixture('cnb_daily.json'));

        return $http;
    }

    public function testEurFromJson(): void
    {
        $http = $this->http();
        $q    = (new CnbRateProvider($http))->latestOnOrBefore('EUR', '2026-10-31');

        $this->assertSame([self::JSON], $http->calls);
        $this->assertSame('CNB', $q->source);
        $this->assertSame('24.335', $q->rate);
        $this->assertSame(1, $q->amount);
        $this->assertSame('2026-10-29', $q->validFor);
        $this->assertSame('208', $q->tableNo);
        $this->assertSame('multiply', $q->direction);
    }

    public function testHufKeepsAmountAndPublishedRate(): void
    {
        $q = (new CnbRateProvider($this->http()))->latestOnOrBefore('HUF', '2026-10-31');

        $this->assertSame(100, $q->amount);
        $this->assertSame('6.650', $q->rate);
    }

    public function testValidForAfterBoundIsNotFound(): void
    {
        $http = new FakeRateHttpClient();
        $http->respond(self::JSON, 200, '{"rates":[{"validFor":"2026-11-02","order":210,"currencyCode":"EUR","amount":1,"rate":24.4}]}');

        $this->expectException(RateNotFound::class);
        (new CnbRateProvider($http))->latestOnOrBefore('EUR', '2026-10-31');
    }

    public function testMissingCurrencyIsNotFoundWithoutFallback(): void
    {
        $http = $this->http();

        try {
            (new CnbRateProvider($http))->latestOnOrBefore('XYZ', '2026-10-31');
            $this->fail('Expected RateNotFound');
        } catch (RateNotFound) {
            $this->assertSame([self::JSON], $http->calls);
        }
    }

    public function testTextFallbackWhenJsonFails(): void
    {
        foreach ([500, 'throwable'] as $failure) {
            $http = new FakeRateHttpClient();
            if ($failure === 500) {
                $http->respond(self::JSON, 500, 'x');
            } else {
                $http->failWith(self::JSON, new RateUnavailable('http_error'));
            }
            $http->respond(self::TXT, 200, FakeRateHttpClient::fixture('cnb_daily.txt'));

            $q = (new CnbRateProvider($http))->latestOnOrBefore('EUR', '2026-10-31');

            $this->assertSame('24.335', $q->rate);
            $this->assertSame('2026-10-29', $q->validFor);
            $this->assertSame('208', $q->tableNo);
            $this->assertSame([self::JSON, self::TXT], $http->calls);
        }

        $http = new FakeRateHttpClient();
        $http->respond(self::JSON, 500, 'x');
        $http->respond(self::TXT, 200, FakeRateHttpClient::fixture('cnb_daily.txt'));
        $this->assertSame(100, (new CnbRateProvider($http))->latestOnOrBefore('HUF', '2026-10-31')->amount);
    }

    public function testBothFailIsUnavailable(): void
    {
        $http = new FakeRateHttpClient();
        $http->respond(self::JSON, 503, 'x');
        $http->respond(self::TXT, 500, 'y');

        $this->expectException(RateUnavailable::class);
        (new CnbRateProvider($http))->latestOnOrBefore('EUR', '2026-10-31');
    }

    public function testNonRuntimeThrowableFromClientIsNotSwallowed(): void
    {
        $http = new FakeRateHttpClient();
        $http->failWith(self::JSON, new RuntimeException('x'));

        $this->expectException(RuntimeException::class);
        (new CnbRateProvider($http))->latestOnOrBefore('EUR', '2026-10-31');
    }

    public function testRegistry(): void
    {
        $p = RateProviders::for('CNB', new FakeRateHttpClient());

        $this->assertInstanceOf(CnbRateProvider::class, $p);
        $this->assertSame('CZK', $p->homeCurrency());
        $this->assertSame('multiply', $p->direction());
    }
}
