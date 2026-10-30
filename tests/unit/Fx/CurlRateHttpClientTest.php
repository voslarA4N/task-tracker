<?php

declare(strict_types=1);

namespace Tests\Unit\Fx;

use App\Libraries\Fx\CurlRateHttpClient;
use App\Libraries\Fx\RateUnavailable;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCURLRequest;
use Config\App;

/**
 * CR-01: produkcny klient sa musi dat zostavit a programova chyba sa nesmie skryt za http_error.
 * Ziadne volanie zivych API: iba MockCURLRequest a lokalny uzavrety port.
 *
 * @internal
 */
final class CurlRateHttpClientTest extends CIUnitTestCase
{
    public function testServiceBuildsProductionClient(): void
    {
        $this->assertInstanceOf(CurlRateHttpClient::class, service('rateHttpClient'));
        $this->assertInstanceOf(CurlRateHttpClient::class, single_service('rateHttpClient'));
    }

    public function testRealCurlRequestIsBuiltAndTransportErrorMapsToHttpError(): void
    {
        // Lokalny uzavrety port: spojenie zlyha okamzite, bez siete von. Ak by zostavenie klienta
        // hodilo TypeError (CR-01), test spadne na nom, nie na RateUnavailable.
        try {
            (new CurlRateHttpClient())->get('http://127.0.0.1:1/');
            $this->fail('Expected RateUnavailable.');
        } catch (RateUnavailable $e) {
            $this->assertSame('http_error', $e->reason);
            $this->assertInstanceOf(HTTPException::class, $e->getPrevious());
        }
    }

    public function testMapsStatusAndBodyWithoutThrowingOn4xx(): void
    {
        $mock = $this->mock();
        $mock->setOutput("HTTP/1.1 404 Not Found\r\nContent-Type: text/plain\r\n\r\nNot found");

        $result = (new CurlRateHttpClient($mock))->get('https://api.nbp.pl/x');

        $this->assertSame(404, $result->status);
        $this->assertSame('Not found', $result->body);
    }

    public function testPassesShortTimeoutsAndVerifiesCertificate(): void
    {
        $mock = $this->mock();
        $mock->setOutput("HTTP/1.1 200 OK\r\n\r\n{}");

        $result = (new CurlRateHttpClient($mock))->get('https://api.nbp.pl/x');

        $this->assertSame(200, $result->status);
        $this->assertSame(3000, (int) $mock->curl_options[CURLOPT_TIMEOUT_MS]);
        $this->assertSame(2000, (int) $mock->curl_options[CURLOPT_CONNECTTIMEOUT_MS]);
        $this->assertSame(2, $mock->curl_options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertTrue($mock->curl_options[CURLOPT_SSL_VERIFYPEER]);
    }

    private function mock(): MockCURLRequest
    {
        $config = config(App::class);

        return new MockCURLRequest($config, new URI(), new Response($config), []);
    }
}
