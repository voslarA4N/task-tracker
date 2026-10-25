<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Výber verzie údajov subjektu k dátumu (D-04). Phase 5 ho zavolá s dátumom vystavenia.
 */
final class EntityVersionResolver
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
     * @return array<string, mixed>
     */
    public function atDate(int $entityId, string $date): array
    {
        $row = $this->db->query(
            'SELECT * FROM entity_versions WHERE entity_id = :e: AND valid_from <= :d: ORDER BY valid_from DESC LIMIT 1',
            ['e' => $entityId, 'd' => $date],
        )->getRowArray();

        if ($row === null) {
            throw new VersionNotFound('No entity version valid at ' . $date . ' for entity ' . $entityId);
        }

        return $row;
    }

    /**
     * Verzia platná dnes, inak najskoršia (subjekt s budúcim active_from).
     *
     * @return array<string, mixed>
     */
    public function current(int $entityId, string $today): array
    {
        try {
            return $this->atDate($entityId, $today);
        } catch (VersionNotFound $e) {
            $row = $this->db->query(
                'SELECT * FROM entity_versions WHERE entity_id = :e: ORDER BY valid_from ASC LIMIT 1',
                ['e' => $entityId],
            )->getRowArray();

            if ($row === null) {
                throw $e;
            }

            return $row;
        }
    }

    /**
     * Verzia iba ak patrí subjektu (IDOR).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $entityId, int $versionId): ?array
    {
        return $this->db->query(
            'SELECT * FROM entity_versions WHERE id = :v: AND entity_id = :e:',
            ['v' => $versionId, 'e' => $entityId],
        )->getRowArray();
    }

    /**
     * Najbližšia verzia s valid_from > date.
     *
     * @return array<string, mixed>|null
     */
    public function nextAfter(int $entityId, string $date): ?array
    {
        return $this->db->query(
            'SELECT * FROM entity_versions WHERE entity_id = :e: AND valid_from > :d: ORDER BY valid_from ASC LIMIT 1',
            ['e' => $entityId, 'd' => $date],
        )->getRowArray();
    }

    /**
     * História od najnovšej.
     *
     * @return list<array{id: int, valid_from: string, values: array<string, string|null>, changed: list<string>, is_current: bool, is_future: bool}>
     */
    public function history(int $entityId, string $today): array
    {
        $rows = $this->db->query(
            'SELECT * FROM entity_versions WHERE entity_id = :e: ORDER BY valid_from ASC',
            ['e' => $entityId],
        )->getResultArray();

        $out     = [];
        $older   = null;
        $current = null;

        foreach ($rows as $row) {
            if ((string) $row['valid_from'] <= $today) {
                $current = (int) $row['id'];
            }
        }

        foreach ($rows as $row) {
            $values = [];

            foreach (EntityInput::VERSION_FIELDS as $field) {
                $values[$field] = $row[$field] === null ? null : (string) $row[$field];
            }

            $out[] = [
                'id'         => (int) $row['id'],
                'valid_from' => (string) $row['valid_from'],
                'values'     => $values,
                'changed'    => VersionDiff::changedFields($older, $values),
                'is_current' => (int) $row['id'] === $current,
                'is_future'  => (string) $row['valid_from'] > $today,
            ];
            $older = $values;
        }

        return array_reverse($out);
    }
}
