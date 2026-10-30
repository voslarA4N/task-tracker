<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\BankAccountService;

/**
 * Fixtures kurzov (Phase 8). Pouziva MasterFixtures a IssueFixtures.
 *
 * @mixin PgTestCase
 */
trait FxFixtures
{
    /**
     * PL subjekt (PLN) + klient v EUR + druhy bankovy ucet subjektu v EUR.
     *
     * @param array<string, mixed> $opts volby issueSetup
     *
     * @return array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int}
     */
    protected function fxPlEurSetup(array $opts = []): array
    {
        $opts['country'] = 'PL';
        $opts['client']  = ($opts['client'] ?? []) + ['currency' => 'EUR'];
        $setup           = $this->issueSetup($opts);

        (new BankAccountService($this->rw(), new AuditLogger($this->rw())))->add($setup['entity'], [
            'label'      => 'Konto EUR',
            'iban'       => 'PL27114020040000300201355387',
            'swift'      => 'BREXPLPW',
            'currency'   => 'EUR',
            'is_default' => false,
        ]);

        return $setup;
    }

    protected function seedRate(string $source, string $currency, string $bound, string $validFor, string $rate, int $amount = 1, ?string $tableNo = null, ?string $fetchedAt = null): void
    {
        $this->owner()->query(
            'INSERT INTO exchange_rate_cache (source, currency, bound_date, valid_for, rate, rate_published, amount, table_no, direction, fetched_at)
             VALUES (:s:, :c:, :b:, :v:, :r:, :r:, :a:, :t:, :d:, :f:)',
            [
                's' => $source,
                'c' => $currency,
                'b' => $bound,
                'v' => $validFor,
                'r' => $rate,
                'a' => $amount,
                't' => $tableNo,
                'd' => $source === 'ECB' ? 'divide' : 'multiply',
                'f' => $fetchedAt ?? '2026-10-30 09:59:00+01',
            ],
        );
    }

    protected function restoreFxSettings(): void
    {
        foreach (['SK' => ['ECB', 'previous_business_day'], 'PL' => ['NBP', 'previous_business_day'], 'CZ' => ['CNB', 'on_or_before']] as $country => [$source, $rule]) {
            $this->owner()->query(
                "UPDATE fx_country_settings SET source = :s:, date_basis = 'delivery_date', day_rule = :r: WHERE country = :c:",
                ['s' => $source, 'r' => $rule, 'c' => $country],
            );
        }
    }
}
