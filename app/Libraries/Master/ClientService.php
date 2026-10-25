<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\DbBool;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use Throwable;

/**
 * Klient: jedna tabulka bez verzii, bez DELETE (D-15). Zmeny su v audit_log v tej istej transakcii.
 */
final class ClientService
{
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
     * @param array<string, mixed> $values normalizovane hodnoty (ClientInput::normalize)
     */
    public function create(array $values): int
    {
        $now  = service('clock')->now()->format('Y-m-d H:i:sP');
        $data = $this->dbValues($values);

        $this->db->transException(true)->transStart();

        try {
            $columns = array_keys($data);
            $sql     = 'INSERT INTO clients (' . implode(', ', $columns) . ', created_at, updated_at) VALUES ('
                . implode(', ', array_map(static fn (string $c): string => self::placeholder($c), $columns))
                . ', :now:, :now:) RETURNING id';
            $id = (int) $this->db->query($sql, $data + ['now' => $now])->getRow()->id;

            $this->audit->record('client', $id, 'create', null, ['id' => $id] + $this->auditValues($values));
            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        } finally {
            $this->db->transException(false);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $values normalizovane hodnoty
     */
    public function update(int $id, array $values): bool
    {
        $before = $this->find($id);

        if ($before === null) {
            throw new InvalidArgumentException('Client ' . $id . ' not found');
        }

        $after = $this->auditValues($values);

        if ($after === $this->auditValues($before)) {
            return false;
        }

        $data    = $this->dbValues($values);
        $now     = service('clock')->now()->format('Y-m-d H:i:sP');
        $assigns = array_map(static fn (string $c): string => $c . ' = ' . self::placeholder($c), array_keys($data));
        $changed = false;

        $this->db->transException(true)->transStart();

        try {
            $this->db->query(
                'UPDATE clients SET ' . implode(', ', $assigns) . ', updated_at = :now: WHERE id = :id:',
                $data + ['now' => $now, 'id' => $id],
            );
            $changed = $this->audit->recordChange('client', $id, 'update', $this->auditValues($before), $after);
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
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->query('SELECT * FROM clients WHERE id = :id:', ['id' => $id])->getRowArray();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(bool $archived = false): array
    {
        $where = $archived ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';
        $rows  = $this->db->query('SELECT * FROM clients WHERE ' . $where . ' ORDER BY name, id')->getResultArray();

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    private static function placeholder(string $column): string
    {
        return match ($column) {
            'cc_emails'           => 'CAST(:cc_emails: AS jsonb)',
            'counts_toward_limit' => 'CAST(:counts_toward_limit: AS boolean)',
            default               => ':' . $column . ':',
        };
    }

    /**
     * Hodnoty pre SQL: iba FIELDS, cc_emails ako JSON, boolean ako 'true'/'false'.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function dbValues(array $values): array
    {
        $data = [];

        foreach (ClientInput::FIELDS as $field) {
            $value = $values[$field] ?? null;

            $data[$field] = match ($field) {
                'cc_emails'           => json_encode(array_values((array) $value), JSON_THROW_ON_ERROR),
                'counts_toward_limit' => DbBool::toDb((bool) $value),
                default               => $value === '' ? null : $value,
            };
        }

        return $data;
    }

    /**
     * Porovnatelna a auditovatelna podoba FIELDS.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function auditValues(array $values): array
    {
        $out = [];

        foreach (ClientInput::FIELDS as $field) {
            $value = $values[$field] ?? null;

            $out[$field] = match ($field) {
                'cc_emails'           => array_values((array) $value),
                'counts_toward_limit' => (bool) $value,
                'due_days'            => $value === null || $value === '' ? null : (int) $value,
                default               => $value === ''   || $value === null ? null : (string) $value,
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $cc = json_decode((string) $row['cc_emails'], true, 512, JSON_THROW_ON_ERROR);

        $row['cc_emails']           = is_array($cc) ? array_values(array_map('strval', $cc)) : [];
        $row['counts_toward_limit'] = DbBool::of($row['counts_toward_limit']);
        $row['hourly_rate']         = (string) $row['hourly_rate'];
        $row['due_days']            = $row['due_days'] === null ? null : (int) $row['due_days'];

        return $row;
    }
}
