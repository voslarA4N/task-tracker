<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Najde kurz podla nastavenia krajiny, cachuje ho podla bound date (D-07) a vie ho precitat bez siete.
 * Siet nikdy nebezi v transakcii ani pod zamkom (T-08-14).
 */
class FxService
{
    public const REFRESH_MINUTES = 15;
    public const FAILURE_TTL     = 60;

    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;
    private readonly RateHttpClient $http;
    private readonly FxSettingsService $settings;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?RateHttpClient $http = null, ?FxSettingsService $settings = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->http     = $http ?? service('rateHttpClient');
        $this->settings = $settings ?? new FxSettingsService($this->db);
    }

    public function context(string $country, string $entityCurrency, string $invoiceCurrency, string $issueDate, string $deliveryDate): ?FxContext
    {
        if ($entityCurrency === $invoiceCurrency) {
            return null;
        }

        $s         = $this->settings->forCountry($country);
        $anchor    = FxBoundDate::anchor($s['date_basis'], $issueDate, $deliveryDate);
        $bound     = FxBoundDate::bound($s['day_rule'], $anchor);
        $supported = (RateProviders::HOME[$s['source']] ?? null) === $entityCurrency;
        $direction = $supported ? RateProviders::for($s['source'], $this->http)->direction() : 'multiply';

        return new FxContext($country, $s['source'], $s['date_basis'], $s['day_rule'], $anchor, $bound, $entityCurrency, $invoiceCurrency, $supported, $direction);
    }

    /**
     * @throws UnsupportedPair
     * @throws RateNotFound
     * @throws RateUnavailable
     */
    public function resolve(FxContext $ctx, bool $retry = false): FxQuote
    {
        if (! $ctx->supported) {
            throw new UnsupportedPair('Source does not support this currency pair.');
        }

        $row = $this->selectRow($ctx);

        if ($row !== null && ($this->isFinal($ctx) || $row['fresh'])) {
            return $this->quoteFromRow($ctx, $row);
        }

        $key = $this->failureKey($ctx);

        if ($retry) {
            cache()->delete($key);
        }

        if (cache()->get($key) !== null) {
            if ($row !== null) {
                return $this->quoteFromRow($ctx, $row);
            }

            throw new RateUnavailable('cached_failure');
        }

        try {
            $q = RateProviders::for($ctx->source, $this->http)->latestOnOrBefore($ctx->invoiceCurrency, $ctx->bound);
        } catch (RateUnavailable $e) {
            log_message('error', 'FX source unavailable: source={source} currency={currency} bound={bound} reason={reason} cause={cause}', [
                'source'   => $ctx->source,
                'currency' => $ctx->invoiceCurrency,
                'bound'    => $ctx->bound,
                'reason'   => $e->reason . ($e->status !== null ? ':' . $e->status : ''),
                'cause'    => $e->getPrevious() === null ? '-' : $e->getPrevious()::class . ':' . $e->getPrevious()->getCode(),
            ]);
            cache()->save($key, 1, self::FAILURE_TTL);

            if ($row !== null) {
                return $this->quoteFromRow($ctx, $row);
            }

            throw $e;
        } catch (RateNotFound $e) {
            log_message('warning', 'FX rate not found: source={source} currency={currency} bound={bound}', [
                'source'   => $ctx->source,
                'currency' => $ctx->invoiceCurrency,
                'bound'    => $ctx->bound,
            ]);

            throw $e;
        }

        $this->upsert($ctx, $q);
        $fresh = $this->selectRow($ctx);

        if ($fresh === null) {
            throw new RateUnavailable('http_error');
        }

        return $this->quoteFromRow($ctx, $fresh);
    }

    /**
     * Iba cita DB, nikdy nevola siet (vystavenie bez siete, D-07).
     */
    public function cached(FxContext $ctx): ?FxQuote
    {
        if (! $ctx->supported) {
            return null;
        }

        $row = $this->selectRow($ctx);

        return $row === null ? null : $this->quoteFromRow($ctx, $row);
    }

    private function isFinal(FxContext $ctx): bool
    {
        return $ctx->bound < service('clock')->today();
    }

    private function failureKey(FxContext $ctx): string
    {
        return 'fx_fail_' . $ctx->source . '_' . $ctx->invoiceCurrency . '_' . $ctx->bound;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectRow(FxContext $ctx): ?array
    {
        $cutoff = service('clock')->now()->modify('-' . self::REFRESH_MINUTES . ' minutes')->format('Y-m-d H:i:sP');
        $row    = $this->db->query(
            'SELECT *, (fetched_at > :cutoff:) AS fresh FROM exchange_rate_cache WHERE source = :s: AND currency = :c: AND bound_date = :b:',
            ['cutoff' => $cutoff, 's' => $ctx->source, 'c' => $ctx->invoiceCurrency, 'b' => $ctx->bound],
        )->getRowArray();

        if ($row === null) {
            return null;
        }

        $row['fresh'] = $row['fresh'] === true || $row['fresh'] === 't';

        return $row;
    }

    private function upsert(FxContext $ctx, RateQuote $q): void
    {
        $this->db->query(
            'INSERT INTO exchange_rate_cache (source, currency, bound_date, valid_for, rate, rate_published, amount, table_no, direction, fetched_at)
             VALUES (:s:, :c:, :b:, :v:, :r:, :r:, :a:, :t:, :d:, :f:)
             ON CONFLICT (source, currency, bound_date) DO UPDATE SET
                valid_for = EXCLUDED.valid_for, rate = EXCLUDED.rate, rate_published = EXCLUDED.rate_published,
                amount = EXCLUDED.amount, table_no = EXCLUDED.table_no, direction = EXCLUDED.direction, fetched_at = EXCLUDED.fetched_at
             WHERE exchange_rate_cache.bound_date >= :today:',
            [
                's'     => $ctx->source,
                'c'     => $ctx->invoiceCurrency,
                'b'     => $ctx->bound,
                'v'     => $q->validFor,
                'r'     => $q->rate,
                'a'     => $q->amount,
                't'     => $q->tableNo,
                'd'     => $q->direction,
                'f'     => service('clock')->now()->format('Y-m-d H:i:sP'),
                'today' => service('clock')->today(),
            ],
        );
    }

    /**
     * Jediny mapper riadku cache na FxQuote: nahlad aj vystavenie maju bajtovo zhodne core().
     *
     * @param array<string, mixed> $row
     */
    private function quoteFromRow(FxContext $ctx, array $row): FxQuote
    {
        $q = new RateQuote(
            (string) $row['source'],
            trim((string) $row['currency']),
            (string) $row['rate_published'],
            (int) $row['amount'],
            (string) $row['valid_for'],
            $row['table_no'] === null ? null : (string) $row['table_no'],
            (string) $row['direction'],
        );

        return FxQuote::fromRate($q, $ctx->dateBasis, $ctx->dayRule, $ctx->anchor, $ctx->entityCurrency, $ctx->invoiceCurrency);
    }
}
