<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use App\Libraries\Audit\AuditLogger;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

/**
 * Nastavenie kurzu podla krajiny subjektu (D-05). Tu iba citanie, zapis pridava 08-08.
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
