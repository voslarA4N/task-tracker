<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use App\Libraries\Audit\AuditLogger;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use InvalidArgumentException;
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
     * Nová verzia údajov subjektu s dátumom „platné od“ (D-04). Iba INSERT, audit v tej istej transakcii.
     *
     * @param array<string, string|null> $version normalizovaná a validovaná VERSION_FIELDS
     *
     * @throws VersionRejected
     */
    public function newVersion(int $entityId, string $validFrom, array $version): int
    {
        $entity = $this->find($entityId);

        if ($entity === null) {
            throw new InvalidArgumentException('Entity ' . $entityId . ' not found');
        }

        $clock = service('clock');
        $max   = ($clock->parseDate($clock->today()) ?? $clock->now())->modify('+366 days')->format('Y-m-d');

        if ($validFrom < (string) $entity['active_from'] || $validFrom > $max) {
            throw VersionRejected::outOfRange();
        }

        $exists = $this->db->query(
            'SELECT 1 FROM entity_versions WHERE entity_id = :e: AND valid_from = :d:',
            ['e' => $entityId, 'd' => $validFrom],
        )->getRowArray();

        if ($exists !== null) {
            throw VersionRejected::duplicateDate();
        }

        $previous = (new EntityVersionResolver($this->db))->atDate($entityId, $validFrom);

        if (VersionDiff::changedFields($previous, $version) === []) {
            throw VersionRejected::noChange();
        }

        $now    = $clock->now()->format('Y-m-d H:i:sP');
        $userId = auth()->id();
        $row    = ['entity_id' => $entityId, 'valid_from' => $validFrom];

        foreach (EntityInput::VERSION_FIELDS as $field) {
            $value       = $version[$field] ?? null;
            $row[$field] = $value === '' ? null : $value;
        }

        $row['created_at'] = $now;
        $row['created_by'] = is_numeric($userId) ? (int) $userId : null;

        $beforeFields = [];

        foreach (EntityInput::VERSION_FIELDS as $field) {
            $beforeFields[$field] = $previous[$field] === null ? null : (string) $previous[$field];
        }

        $afterFields = [];

        foreach (EntityInput::VERSION_FIELDS as $field) {
            $afterFields[$field] = $row[$field] === null ? null : (string) $row[$field];
        }

        [$before, $after]    = AuditLogger::diff($beforeFields, $afterFields);
        $after['valid_from'] = $validFrom;
        $after['entity_id']  = $entityId;

        $versionId = 0;

        try {
            $this->db->transException(true)->transStart();

            try {
                $versionId = $this->insert('entity_versions', $row);
                $this->audit->record('entity_version', $versionId, 'create', $before, $after);
                $this->db->transComplete();
            } catch (Throwable $e) {
                $this->db->transRollback();
                $this->db->resetTransStatus();

                throw $e;
            } finally {
                $this->db->transException(false);
            }
        } catch (DatabaseException $e) {
            if (str_contains($e->getMessage(), 'entity_versions_entity_id_valid_from_key') || str_contains($e->getMessage(), '23505')) {
                throw VersionRejected::duplicateDate();
            }

            throw $e;
        }

        return $versionId;
    }

    /**
     * Úprava interného názvu a obdobia pôsobenia (D-01). Zapisuje len stĺpce z grantu, audit v tej istej transakcii.
     *
     * @param array<string, string|null> $values BASE_UPDATE_FIELDS
     *
     * @return bool true, ak nastala zmena
     */
    public function updateBase(int $id, array $values): bool
    {
        $entity = $this->find($id);

        if ($entity === null) {
            throw new InvalidArgumentException('Entity ' . $id . ' not found');
        }

        $new = [
            'name_internal' => (string) $values['name_internal'],
            'active_from'   => (string) $values['active_from'],
            'active_to'     => ($values['active_to'] ?? '') === '' ? null : (string) $values['active_to'],
        ];
        $old = [
            'name_internal' => (string) $entity['name_internal'],
            'active_from'   => (string) $entity['active_from'],
            'active_to'     => $entity['active_to'] === null ? null : (string) $entity['active_to'],
        ];

        if ($old === $new) {
            return false;
        }

        $now     = service('clock')->now()->format('Y-m-d H:i:sP');
        $changed = false;

        $this->db->transException(true)->transStart();

        try {
            $this->db->query(
                'UPDATE entities SET name_internal = :n:, active_from = :f:, active_to = :t:, updated_at = :now: WHERE id = :id:',
                ['n' => $new['name_internal'], 'f' => $new['active_from'], 't' => $new['active_to'], 'now' => $now, 'id' => $id],
            );
            $changed = $this->audit->recordChange('entity', $id, 'update', $old, $new);
            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        } finally {
            $this->db->transException(false);
        }

        return $changed;
    }

    /**
     * Ukončenie subjektu dátumom „Aktívny do“ (D-07). Subjekt ostáva zobraziteľný.
     */
    public function end(int $id, string $activeTo): void
    {
        $entity = $this->find($id);

        if ($entity === null) {
            throw new InvalidArgumentException('Entity ' . $id . ' not found');
        }

        if ($activeTo < (string) $entity['active_from']) {
            throw new InvalidArgumentException('active_to is before active_from');
        }

        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        $this->db->transException(true)->transStart();

        try {
            $this->db->query(
                'UPDATE entities SET active_to = :t:, updated_at = :now: WHERE id = :id:',
                ['t' => $activeTo, 'now' => $now, 'id' => $id],
            );
            $this->audit->record(
                'entity',
                $id,
                'end',
                ['active_to' => $entity['active_to'] === null ? null : (string) $entity['active_to']],
                ['active_to' => $activeTo],
            );
            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        } finally {
            $this->db->transException(false);
        }
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
