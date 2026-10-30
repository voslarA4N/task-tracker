<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use InvalidArgumentException;
use JsonException;

/**
 * CNB denny kurzovy listok: JSON API, pri vypadku textovy daily.txt. Kurz sa vracia publikovany spolu s amount (D-06, D-09).
 */
final class CnbRateProvider implements RateProvider
{
    private const URL_JSON = 'https://api.cnb.cz/cnbapi/exrates/daily?date=%s&lang=EN';
    private const URL_TXT  = 'https://www.cnb.cz/en/financial-markets/foreign-exchange-market/central-bank-exchange-rate-fixing/central-bank-exchange-rate-fixing/daily.txt?date=%s';
    private const MONTHS   = ['Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06',
        'Jul'                       => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'];

    public function __construct(private readonly RateHttpClient $http)
    {
    }

    public function source(): string
    {
        return 'CNB';
    }

    public function homeCurrency(): string
    {
        return 'CZK';
    }

    public function direction(): string
    {
        return 'multiply';
    }

    public function latestOnOrBefore(string $currency, string $bound): RateQuote
    {
        RateWindow::currency($currency);
        [, $to] = RateWindow::range($bound);

        try {
            return $this->fromJson($currency, $to);
        } catch (RateUnavailable) {
            // Zaloha iba pri vypadku JSON API, nie pri RateNotFound.
            return $this->fromText($currency, $to);
        }
    }

    private function fromJson(string $currency, string $bound): RateQuote
    {
        $result = $this->http->get(sprintf(self::URL_JSON, $bound));

        if ($result->status !== 200) {
            throw new RateUnavailable('http_status', $result->status);
        }

        // Kurz nesmie prejst cez float (D-09).
        $body = preg_replace('/"rate"\s*:\s*(-?[0-9]+(?:\.[0-9]+)?)/', '"rate":"$1"', $result->body);

        try {
            $data = json_decode((string) $body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RateUnavailable('parse_error');
        }

        $rates = is_array($data) ? ($data['rates'] ?? null) : null;

        if (! is_array($rates)) {
            throw new RateUnavailable('parse_error');
        }

        foreach ($rates as $row) {
            if (! is_array($row) || ($row['currencyCode'] ?? null) !== $currency) {
                continue;
            }

            if (! is_string($row['rate'] ?? null) || ! is_int($row['amount'] ?? null)
                                                  || ! is_string($row['validFor'] ?? null) || ! is_int($row['order'] ?? null)) {
                throw new RateUnavailable('parse_error');
            }

            return $this->quote($currency, $row['rate'], $row['amount'], $row['validFor'], (string) $row['order'], $bound);
        }

        throw new RateNotFound('Currency not published by CNB.');
    }

    private function fromText(string $currency, string $bound): RateQuote
    {
        [$y, $m, $d] = explode('-', $bound);
        $result      = $this->http->get(sprintf(self::URL_TXT, $d . '.' . $m . '.' . $y));

        if ($result->status !== 200) {
            throw new RateUnavailable('http_status', $result->status);
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($result->body));

        if ($lines === false || ! isset($lines[0]) || preg_match('/^(\d{1,2}) (\w{3}) (\d{4}) #(\d+)$/D', trim($lines[0]), $h) !== 1
                             || ! isset(self::MONTHS[$h[2]])) {
            throw new RateUnavailable('parse_error');
        }

        $validFor = sprintf('%s-%s-%02d', $h[3], self::MONTHS[$h[2]], (int) $h[1]);

        foreach (array_slice($lines, 2) as $line) {
            $cols = explode('|', trim($line));

            if (count($cols) !== 5 || $cols[3] !== $currency) {
                continue;
            }

            if (preg_match('/^\d{1,6}$/D', $cols[2]) !== 1) {
                throw new RateUnavailable('parse_error');
            }

            return $this->quote($currency, $cols[4], (int) $cols[2], $validFor, $h[4], $bound);
        }

        throw new RateNotFound('Currency not published by CNB.');
    }

    private function quote(string $currency, string $rate, int $amount, string $validFor, string $tableNo, string $bound): RateQuote
    {
        try {
            $quote = new RateQuote('CNB', $currency, $rate, $amount, $validFor, $tableNo, 'multiply');
        } catch (InvalidArgumentException) {
            throw new RateUnavailable('parse_error');
        }

        if ($validFor > $bound) {
            throw new RateNotFound('CNB rate is newer than the bound date.');
        }

        return $quote;
    }
}
