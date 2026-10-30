<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use InvalidArgumentException;

/**
 * ECB referencny kurz cez SDMX csvdata: jednotiek cudzej meny za 1 EUR, smer divide (D-06, D-09).
 */
final class EcbRateProvider implements RateProvider
{
    private const URL = 'https://data-api.ecb.europa.eu/service/data/EXR/D.%s.EUR.SP00.A?startPeriod=%s&endPeriod=%s&format=csvdata';

    public function __construct(private readonly RateHttpClient $http)
    {
    }

    public function source(): string
    {
        return 'ECB';
    }

    public function homeCurrency(): string
    {
        return 'EUR';
    }

    public function direction(): string
    {
        return 'divide';
    }

    public function latestOnOrBefore(string $currency, string $bound): RateQuote
    {
        RateWindow::currency($currency);
        [$from, $to] = RateWindow::range($bound);

        $result = $this->http->get(sprintf(self::URL, $currency, $from, $to));

        if ($result->status === 404) {
            throw new RateNotFound('No ECB rate in range.');
        }

        if ($result->status !== 200) {
            throw new RateUnavailable('http_status', $result->status);
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($result->body));
        $lines = $lines === false ? [] : array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));

        if (count($lines) < 2) {
            throw new RateNotFound('No ECB rate in range.');
        }

        $header = str_getcsv($lines[0], ',', '"', '');
        $time   = array_search('TIME_PERIOD', $header, true);
        $value  = array_search('OBS_VALUE', $header, true);
        $ccy    = array_search('CURRENCY', $header, true);

        if ($time === false || $value === false || $ccy === false) {
            throw new RateUnavailable('parse_error');
        }

        // WR-04: neveri sa poradiu ani rozsahu odpovede; najnovsi riadok s datumom <= bound a spravnou menou
        $best = null;

        foreach (array_slice($lines, 1) as $line) {
            $row = str_getcsv($line, ',', '"', '');

            if (! isset($row[$time], $row[$value], $row[$ccy])) {
                throw new RateUnavailable('parse_error');
            }

            $date = trim((string) $row[$time]);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
                throw new RateUnavailable('parse_error');
            }

            if (trim((string) $row[$ccy]) !== $currency || $date > $to) {
                continue;
            }

            if ($best === null || $date > $best[0]) {
                $best = [$date, trim((string) $row[$value])];
            }
        }

        if ($best === null) {
            throw new RateNotFound('No ECB rate in range.');
        }

        try {
            return new RateQuote('ECB', $currency, $best[1], 1, $best[0], null, 'divide');
        } catch (InvalidArgumentException) {
            throw new RateUnavailable('parse_error');
        }
    }
}
