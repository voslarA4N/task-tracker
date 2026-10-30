<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

/**
 * Nastavenie kurzu podla krajiny subjektu (D-05). Citanie a atomicky zapis vsetkych krajin naraz (08-08).
 *
 * @phpstan-type FxSetting array{id: int, country: string, source: string, date_basis: string, day_rule: string, revision: int}
 */
class FxSettingsService
{
    public const SOURCES    = ['NBP', 'ECB', 'CNB'];
    public const DATE_BASES = ['delivery_date', 'issue_date'];
    public const DAY_RULES  = ['previous_business_day', 'on_or_before'];
    public const COUNTRIES  = ['SK', 'PL', 'CZ'];

    /**
     * @var BaseConnection<mixed, mixed>
     */
    protected readonly BaseConnection $db;

    protected readonly AuditLogger $audit;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?AuditLogger $audit = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = $audit ?? new AuditLogger($this->db);
    }

    /**
     * @return FxSetting
     */
    public function forCountry(string $country): array
    {
        if (! in_array($country, self::COUNTRIES, true)) {
            throw new InvalidArgumentException('Unknown country.');
        }

        $row = $this->db->query('SELECT * FROM fx_country_settings WHERE country = :c:', ['c' => $country])->getRowArray();

        if ($row === null) {
            throw new InvalidArgumentException('Missing FX settings for country.');
        }

        return $this->map($row);
    }

    /**
     * @return list<FxSetting>
     */
    public function all(): array
    {
        $rows = $this->db->query(
            "SELECT * FROM fx_country_settings ORDER BY CASE country WHEN 'SK' THEN 0 WHEN 'PL' THEN 1 ELSE 2 END",
        )->getResultArray();

        return array_map(fn (array $r): array => $this->map($r), $rows);
    }

    /**
     * Zapis nastavenia vsetkych krajin naraz (D-05, CUR-05). Zmenene riadky dostanu novu reviziu a audit v tej istej transakcii.
     *
     * @param array<string, array<string, mixed>> $rows
     *
     * @return bool true ak sa nieco zmenilo
     *
     * @throws FxSettingsRejected
     */
    public function updateAll(array $rows): bool
    {
        $invalid = [];
        $allowed = ['source' => self::SOURCES, 'date_basis' => self::DATE_BASES, 'day_rule' => self::DAY_RULES];

        foreach (self::COUNTRIES as $country) {
            if (! isset($rows[$country])) {
                $invalid[] = $country;

                continue;
            }

            foreach ($allowed as $field => $values) {
                $v = $rows[$country][$field] ?? null;

                if (! is_string($v) || ! in_array($v, $values, true)) {
                    $invalid[] = $country . '.' . $field;
                }
            }
        }

        if ($invalid !== []) {
            throw new FxSettingsRejected('invalid', $invalid);
        }

        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($rows, $now): bool {
            $current   = $this->db->query('SELECT * FROM fx_country_settings ORDER BY id FOR UPDATE')->getResultArray();
            $byCountry = [];

            foreach ($current as $r) {
                $byCountry[(string) $r['country']] = $this->map($r);
            }

            foreach (self::COUNTRIES as $country) {
                if (! isset($byCountry[$country])) {
                    throw new FxSettingsRejected('not_found');
                }

                if ((int) $rows[$country]['revision'] !== $byCountry[$country]['revision']) {
                    throw new FxSettingsRejected('stale');
                }
            }

            $changed = false;

            foreach (self::COUNTRIES as $country) {
                $old = $byCountry[$country];
                $new = $rows[$country];

                if ($old['source'] === $new['source'] && $old['date_basis'] === $new['date_basis'] && $old['day_rule'] === $new['day_rule']) {
                    continue;
                }

                $this->db->query(
                    'UPDATE fx_country_settings SET source = :s:, date_basis = :b:, day_rule = :r:, revision = revision + 1, updated_at = :now: WHERE id = :id:',
                    ['s' => $new['source'], 'b' => $new['date_basis'], 'r' => $new['day_rule'], 'now' => $now, 'id' => $old['id']],
                );
                $this->audit->recordChange(
                    'fx_settings',
                    $old['id'],
                    'update',
                    ['country' => $country, 'source' => $old['source'], 'date_basis' => $old['date_basis'], 'day_rule' => $old['day_rule'], 'revision' => $old['revision']],
                    ['country' => $country, 'source' => $new['source'], 'date_basis' => $new['date_basis'], 'day_rule' => $new['day_rule'], 'revision' => $old['revision'] + 1],
                );
                $changed = true;
            }

            return $changed;
        });
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return FxSetting
     */
    private function map(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'country'    => (string) $r['country'],
            'source'     => (string) $r['source'],
            'date_basis' => (string) $r['date_basis'],
            'day_rule'   => (string) $r['day_rule'],
            'revision'   => (int) $r['revision'],
        ];
    }
}
