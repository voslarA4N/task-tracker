<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Zoznam a vyber subjektov. Ukoncene subjekty sa nikdy neskryvaju (D-07, ENT-04).
 * Datum "dnes" dodava volajuci zo service('clock'), nikdy CURRENT_DATE.
 */
final class EntityDirectory
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
     * Jeden dotaz: subjekt + verzia platna k dnesku (inak najskorsia).
     *
     * @return list<array{id: int, name_internal: string, country: string, currency: string, active_from: string, active_to: ?string, vat_regime: string, status: string}>
     */
    public function listAll(string $today): array
    {
        $rows = $this->db->query(
            'SELECT e.id, e.name_internal, e.country, e.currency, e.active_from, e.active_to, v.vat_regime
             FROM entities e
             LEFT JOIN LATERAL (
                 SELECT ev.vat_regime
                 FROM entity_versions ev
                 WHERE ev.entity_id = e.id
                 ORDER BY (ev.valid_from <= :today:) DESC,
                          CASE WHEN ev.valid_from <= :today: THEN ev.valid_from END DESC,
                          ev.valid_from ASC
                 LIMIT 1
             ) v ON true',
            ['today' => $today],
        )->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $from = (string) $row['active_from'];
            $to   = $row['active_to'] === null ? null : (string) $row['active_to'];

            if ($from > $today) {
                $status = 'prepared';
            } elseif ($to !== null && $to < $today) {
                $status = 'ended';
            } else {
                $status = 'active';
            }

            $out[] = [
                'id'            => (int) $row['id'],
                'name_internal' => (string) $row['name_internal'],
                'country'       => (string) $row['country'],
                'currency'      => (string) $row['currency'],
                'active_from'   => $from,
                'active_to'     => $to,
                'vat_regime'    => (string) ($row['vat_regime'] ?? ''),
                'status'        => $status,
            ];
        }

        $rank = ['active' => 0, 'prepared' => 1, 'ended' => 2];

        usort($out, static function (array $a, array $b) use ($rank): int {
            if ($a['status'] !== $b['status']) {
                return $rank[$a['status']] <=> $rank[$b['status']];
            }

            return match ($a['status']) {
                'active'   => [$b['active_from'], $b['id']] <=> [$a['active_from'], $a['id']],
                'prepared' => [$a['active_from'], $a['id']] <=> [$b['active_from'], $b['id']],
                default    => [(string) $b['active_to'], $b['id']] <=> [(string) $a['active_to'], $a['id']],
            };
        });

        return $out;
    }

    /**
     * Vsetky subjekty bez vynimky (storno, opatovne odoslanie).
     *
     * @return list<array{id: int, name_internal: string, country: string, currency: string, ended: bool}>
     */
    public function forSelect(string $today): array
    {
        $rows = $this->db->query(
            'SELECT id, name_internal, country, currency, (active_to IS NOT NULL AND active_to < :today:) AS ended
             FROM entities ORDER BY name_internal, id',
            ['today' => $today],
        )->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id'            => (int) $row['id'],
                'name_internal' => (string) $row['name_internal'],
                'country'       => (string) $row['country'],
                'currency'      => (string) $row['currency'],
                'ended'         => $row['ended'] === true || $row['ended'] === 't' || $row['ended'] === '1' || $row['ended'] === 1,
            ];
        }

        return $out;
    }

    /**
     * Subjekty aktivne k datumu, najnovsie active_from prve (prekryv nie je chyba).
     *
     * @return list<array{id: int, name_internal: string, country: string, currency: string}>
     */
    public function activeAt(string $date): array
    {
        $rows = $this->db->query(
            'SELECT id, name_internal, country, currency FROM entities
             WHERE active_from <= :d: AND (active_to IS NULL OR active_to >= :d:)
             ORDER BY active_from DESC, id DESC',
            ['d' => $date],
        )->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id'            => (int) $row['id'],
                'name_internal' => (string) $row['name_internal'],
                'country'       => (string) $row['country'],
                'currency'      => (string) $row['currency'],
            ];
        }

        return $out;
    }
}
