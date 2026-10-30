<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use LogicException;

/**
 * Produkcny HTTP klient: CURLRequest s kratkymi timeoutmi, bez vynimky pri 4xx/5xx, s overenim certifikatu (T-08-09, T-08-12).
 *
 * Na RateUnavailable sa mapuju iba transportne chyby (HTTPException). Programove chyby (TypeError a pod.)
 * sa nepohltia, aby sa nedali zamenit za vypadok zdroja (CR-01).
 */
final class CurlRateHttpClient implements RateHttpClient
{
    public const OPTIONS = [
        'timeout'         => 3,
        'connect_timeout' => 2,
        'http_errors'     => false,
        'headers'         => ['Accept' => 'application/json, text/csv, text/plain'],
    ];

    /**
     * @param CURLRequest|null $client iba pre testy (napr. MockCURLRequest); inak sa pre kazdu poziadavku vytvori novy nezdielany klient
     */
    public function __construct(private readonly ?CURLRequest $client = null)
    {
    }

    public function get(string $url): HttpResult
    {
        $client = $this->client ?? $this->newClient();

        try {
            $response = $client->get($url, self::OPTIONS);
        } catch (HTTPException $e) {
            throw new RateUnavailable('http_error', null, $e);
        }

        return new HttpResult($response->getStatusCode(), (string) $response->getBody());
    }

    private function newClient(): CURLRequest
    {
        // single_service: novy nezdielany CURLRequest (zdielany by si pamatal stav medzi volaniami).
        $client = single_service('curlrequest', self::OPTIONS);

        if (! $client instanceof CURLRequest) {
            throw new LogicException('curlrequest service must return CURLRequest.');
        }

        return $client;
    }
}
