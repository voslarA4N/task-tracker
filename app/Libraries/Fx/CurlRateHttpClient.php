<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use Throwable;

/**
 * Produkcny HTTP klient: CURLRequest s kratkymi timeoutmi, bez vynimky pri 4xx/5xx, s overenim certifikatu (T-08-09, T-08-12).
 */
final class CurlRateHttpClient implements RateHttpClient
{
    public function get(string $url): HttpResult
    {
        try {
            $client = service('curlrequest', [
                'timeout'         => 3,
                'connect_timeout' => 2,
                'http_errors'     => false,
                'headers'         => ['Accept' => 'application/json, text/csv, text/plain'],
            ], false);

            $response = $client->get($url);

            return new HttpResult($response->getStatusCode(), (string) $response->getBody());
        } catch (Throwable) {
            throw new RateUnavailable('http_error');
        }
    }
}
