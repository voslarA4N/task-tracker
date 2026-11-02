<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use App\Libraries\Platform\DbBool;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Vyber riadkov limitov pre krajinu a rok (D-05). Ak pre rok nie su ziadne, pouzije sa najblizsi
 * nizsi rok s riadkami, inak najblizsi vyssi; usedYear nesie rok skutocne pouzitych riadkov.
 *
 * @phpstan-type LimitRow array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool}
 */
final class LimitSelector
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{rows: list<LimitRow>, usedYear: ?int}
     */
    public function forCountry(string $country, int $year): array
    {
        $rows = $this->rowsFor($country, $year);

        if ($rows !== []) {
            return ['rows' => $rows, 'usedYear' => null];
        }

        $lower = $this->db->query('SELECT max(year) AS y FROM turnover_limits WHERE country = :c: AND year < :y:', ['c' => $country, 'y' => $year])->getRow();
        $used  = $lower?->y;

        if ($used === null) {
            $higher = $this->db->query('SELECT min(year) AS y FROM turnover_limits WHERE country = :c: AND year > :y:', ['c' => $country, 'y' => $year])->getRow();
            $used   = $higher?->y;
        }

        if ($used === null) {
            return ['rows' => [], 'usedYear' => null];
        }

        return ['rows' => $this->rowsFor($country, (int) $used), 'usedYear' => (int) $used];
    }

    /**
     * @return list<int>
     */
    public static function parseThresholds(string $csv): array
    {
        if (trim($csv) === '') {
            return [];
        }

        return array_map('intval', explode(',', $csv));
    }

    /**
     * @return list<LimitRow>
     */
    private function rowsFor(string $country, int $year): array
    {
        $out = [];

        foreach ($this->db->query(
            "SELECT id, country, year, name, amount, currency, calc_method, array_to_string(thresholds, ',') AS thresholds_csv, is_seed
             FROM turnover_limits WHERE country = :c: AND year = :y: ORDER BY name, id",
            ['c' => $country, 'y' => $year],
        )->getResultArray() as $r) {
            $out[] = [
                'id'          => (int) $r['id'],
                'country'     => trim((string) $r['country']),
                'year'        => (int) $r['year'],
                'name'        => (string) $r['name'],
                'amount'      => (string) $r['amount'],
                'currency'    => trim((string) $r['currency']),
                'calc_method' => (string) $r['calc_method'],
                'thresholds'  => self::parseThresholds((string) $r['thresholds_csv']),
                'is_seed'     => DbBool::of($r['is_seed']),
            ];
        }

        return $out;
    }
}
