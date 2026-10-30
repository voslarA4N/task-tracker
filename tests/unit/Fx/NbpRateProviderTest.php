<?php

declare(strict_types=1);

namespace Tests\Unit\Fx;

use App\Libraries\Fx\NbpRateProvider;
use App\Libraries\Fx\RateNotFound;
use App\Libraries\Fx\RateProviders;
use App\Libraries\Fx\RateUnavailable;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\FakeRateHttpClient;

/**
 * @internal
 */
final class NbpRateProviderTest extends CIUnitTestCase
{
    private const URL = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-19/2026-10-29/?format=json';

    public function testLastElementOfRange(): void
    {
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 200, FakeRateHttpClient::fixture('nbp_eur_range.json'));

        $q = (new NbpRateProvider($http))->latestOnOrBefore('EUR', '2026-10-29');

        $this->assertSame([self::URL], $http->calls);
        $this->assertSame('NBP', $q->source);
        $this->assertSame('4.3951', $q->rate);
        $this->assertSame('2026-10-29', $q->validFor);
        $this->assertSame('211/A/NBP/2026', $q->tableNo);
        $this->assertSame(1, $q->amount);
        $this->assertSame('multiply', $q->direction);
    }

    public function testWeekendAndHolidayPickLastPublishedWithoutCalendar(): void
    {
        $http = new FakeRateHttpClient();
        $http->respond(
            'https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-11-02/2026-11-12/?format=json',
            200,
            '{"rates":[{"no":"1/A","effectiveDate":"2026-11-09","mid":4.2},{"no":"2/A","effectiveDate":"2026-11-10","mid":4.3}]}',
        );

        $q = (new NbpRateProvider($http))->latestOnOrBefore('EUR', '2026-11-12');

        $this->assertSame('2026-11-10', $q->validFor);
        $this->assertSame('4.3', $q->rate);
    }

    public function testPicksLatestRowOnOrBeforeBoundRegardlessOfOrder(): void
    {
        // WR-04: neusporiadane pole a riadok po bound sa ignoruje (inak by upsert porusil CHECK valid_for <= bound_date)
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 200, '{"rates":[{"no":"210/A/NBP/2026","effectiveDate":"2026-10-28","mid":4.4090},'
            . '{"no":"212/A/NBP/2026","effectiveDate":"2026-10-30","mid":9.9999},{"no":"209/A/NBP/2026","effectiveDate":"2026-10-27","mid":4.4056}]}');

        $q = (new NbpRateProvider($http))->latestOnOrBefore('EUR', '2026-10-29');

        $this->assertSame('4.4090', $q->rate);
        $this->assertSame('2026-10-28', $q->validFor);
        $this->assertSame('210/A/NBP/2026', $q->tableNo);
    }

    public function testOnlyRowsAfterBoundAreNotFound(): void
    {
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 200, '{"rates":[{"no":"212/A/NBP/2026","effectiveDate":"2026-10-30","mid":4.4}]}');

        $this->expectException(RateNotFound::class);
        (new NbpRateProvider($http))->latestOnOrBefore('EUR', '2026-10-29');
    }

    public function testRateStaysStringWithTrailingZero(): void
    {
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 200, '{"rates":[{"no":"210/A/NBP/2026","effectiveDate":"2026-10-28","mid":4.4090}]}');

        $this->assertSame('4.4090', (new NbpRateProvider($http))->latestOnOrBefore('EUR', '2026-10-29')->rate);
    }

    public function testBadRequestAndNotFoundMeanNoRate(): void
    {
        foreach ([400, 404] as $status) {
            $http = new FakeRateHttpClient();
            $http->respond(self::URL, $status, FakeRateHttpClient::fixture('nbp_400.txt'));

            try {
                (new NbpRateProvider($http))->latestOnOrBefore('EUR', '2026-10-29');
                $this->fail('Expected RateNotFound for ' . $status);
            } catch (RateNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEmptyRatesIsNotFound(): void
    {
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 200, '{"rates":[]}');

        $this->expectException(RateNotFound::class);
        (new NbpRateProvider($http))->latestOnOrBefore('EUR', '2026-10-29');
    }

    public function testServerErrorIsUnavailable(): void
    {
        foreach ([500, 503] as $status) {
            $http = new FakeRateHttpClient();
            $http->respond(self::URL, $status, 'boom secret-body');

            try {
                (new NbpRateProvider($http))->latestOnOrBefore('EUR', '2026-10-29');
                $this->fail('Expected RateUnavailable');
            } catch (RateUnavailable $e) {
                $this->assertSame('http_status', $e->reason);
                $this->assertStringNotContainsString('secret-body', $e->getMessage());
            }
        }
    }

    public function testInvalidJsonIsParseError(): void
    {
        $http = new FakeRateHttpClient();
        $http->respond(self::URL, 200, '<html>');

        try {
            (new NbpRateProvider($http))->latestOnOrBefore('EUR', '2026-10-29');
            $this->fail('Expected RateUnavailable');
        } catch (RateUnavailable $e) {
            $this->assertSame('parse_error', $e->reason);
        }
    }

    public function testClientThrowableIsUnavailable(): void
    {
        $http = new FakeRateHttpClient();
        $http->failWith(self::URL, new RuntimeException('timeout'));

        $this->expectException(RuntimeException::class);
        (new NbpRateProvider($http))->latestOnOrBefore('EUR', '2026-10-29');
    }

    public function testUnknownUrlIsUnavailable(): void
    {
        $this->expectException(RateUnavailable::class);
        (new NbpRateProvider(new FakeRateHttpClient()))->latestOnOrBefore('EUR', '2026-10-29');
    }

    public function testInvalidCurrencyNeverHitsHttp(): void
    {
        foreach (['eur', 'E/R', 'EURO', '../x'] as $bad) {
            $http = new FakeRateHttpClient();

            try {
                (new NbpRateProvider($http))->latestOnOrBefore($bad, '2026-10-29');
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->assertSame([], $http->calls);
            }
        }
    }

    public function testRegistry(): void
    {
        $p = RateProviders::for('NBP', new FakeRateHttpClient());

        $this->assertInstanceOf(NbpRateProvider::class, $p);
        $this->assertSame('PLN', $p->homeCurrency());
        $this->assertSame('multiply', $p->direction());
        $this->assertSame('NBP', $p->source());

        $this->expectException(InvalidArgumentException::class);
        RateProviders::for('XYZ', new FakeRateHttpClient());
    }
}
