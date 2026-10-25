<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use App\Libraries\Audit\AuditLogger;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * Vytvorenie subjektu: entities + prvá entity_versions + dva invoice_series + audit v jednej transakcii.
 */
final class EntityService
{
    public const DEFAULT_INVOICE_FORMAT     = 'RRRRMM###';
    public const DEFAULT_CREDIT_NOTE_FORMAT = 'DB-RRRRMM###';

    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly AuditLogger $audit;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?AuditLogger $audit = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = $audit ?? new AuditLogger($this->db);
    }

    /**
     * @param array<string, string|null> $base    BASE_FIELDS
     * @param array<string, string|null> $version VERSION_FIELDS (valid_from sa berie z active_from)
     */
    public function create(array $base, array $version): int
    {
        $now      = service('clock')->now()->format('Y-m-d H:i:sP');
        $userId   = auth()->id();
        $activeTo = ($base['active_to'] ?? '') === '' ? null : $base['active_to'];

        $this->db->transException(true)->transStart();

        try {
            $entityRow = [
                'name_internal' => (string) $base['name_internal'],
                'country'       => (string) $base['country'],
                'currency'      => (string) $base['currency'],
                'active_from'   => (string) $base['active_from'],
                'active_to'     => $activeTo,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            $entityId = $this->insert('entities', $entityRow);
            $this->audit->record('entity', $entityId, 'create', null, ['id' => $entityId] + $entityRow);

            $versionRow = ['entity_id' => $entityId, 'valid_from' => (string) $base['active_from']];

            foreach (EntityInput::VERSION_FIELDS as $field) {
                $value              = $version[$field] ?? null;
                $versionRow[$field] = $value === '' ? null : $value;
            }

            $versionRow['created_at'] = $now;
            $versionRow['created_by'] = is_numeric($userId) ? (int) $userId : null;
            $versionId                = $this->insert('entity_versions', $versionRow);
            $this->audit->record('entity_version', $versionId, 'create', null, ['id' => $versionId] + $versionRow);

            foreach (['invoice' => self::DEFAULT_INVOICE_FORMAT, 'credit_note' => self::DEFAULT_CREDIT_NOTE_FORMAT] as $kind => $format) {
                $seriesRow = [
                    'entity_id'      => $entityId,
                    'kind'           => $kind,
                    'format'         => $format,
                    'initial_number' => 1,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
                $seriesId = $this->insert('invoice_series', $seriesRow);
                $this->audit->record('invoice_series', $seriesId, 'create', null, ['id' => $seriesId] + $seriesRow);
            }

            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->db->transRollback();
            // Zlyhaný dotaz nechá transStatus = false, čo by potichu zrušilo ďalšiu transakciu na tom istom spojení.
            $this->db->resetTransStatus();

            throw $e;
        } finally {
            $this->db->transException(false);
        }

        return $entityId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->query('SELECT * FROM entities WHERE id = :id:', ['id' => $id])->getRowArray();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $sql     = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (:'
            . implode(':, :', $columns) . ':) RETURNING id';

        $result = $this->db->query($sql, $row)->getRow();

        return (int) $result->id;
    }
}
