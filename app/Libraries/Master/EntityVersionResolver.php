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
}
