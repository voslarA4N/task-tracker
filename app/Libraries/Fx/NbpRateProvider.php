<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use InvalidArgumentException;
use JsonException;

/**
 * NBP, tabulka A (stredny kurz), PLN za jednotku meny (D-06). Rozsah [bound-10, bound], posledny prvok.
 */
final class NbpRateProvider implements RateProvider
{
    private const URL = 'https://api.nbp.pl/api/exchangerates/rates/a/%s/%s/%s/?format=json';

    public function __construct(private readonly RateHttpClient $http)
    {
    }

    public function source(): string
    {
        return 'NBP';
    }

    public function homeCurrency(): string
    {
        return 'PLN';
    }

    public function direction(): string
    {
        return 'multiply';
    }

    public function latestOnOrBefore(string $currency, string $bound): RateQuote
    {
        RateWindow::currency($currency);
        [$from, $to] = RateWindow::range($bound);

        $result = $this->http->get(sprintf(self::URL, strtolower($currency), $from, $to));

        if ($result->status === 400 || $result->status === 404) {
            throw new RateNotFound('No NBP rate in range.');
        }

        if ($result->status !== 200) {
            throw new RateUnavailable('http_status', $result->status);
        }

        // Kurz nesmie prejst cez float: cislo sa pred dekodovanim obali do retazca (D-09).
        $body = preg_replace('/"mid"\s*:\s*(-?[0-9]+(?:\.[0-9]+)?)/', '"mid":"$1"', $result->body);

        try {
            $data = json_decode((string) $body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RateUnavailable('parse_error');
        }

        $rates = is_array($data) ? ($data['rates'] ?? null) : null;

        if (! is_array($rates)) {
            throw new RateUnavailable('parse_error');
        }

        if ($rates === []) {
            throw new RateNotFound('No NBP rate in range.');
        }

        $last = end($rates);

        if (! is_array($last) || ! is_string($last['mid'] ?? null) || ! is_string($last['effectiveDate'] ?? null) || ! is_string($last['no'] ?? null)) {
            throw new RateUnavailable('parse_error');
        }

        try {
            return new RateQuote('NBP', $currency, $last['mid'], 1, $last['effectiveDate'], $last['no'], 'multiply');
        } catch (InvalidArgumentException) {
            throw new RateUnavailable('parse_error');
        }
    }
}
