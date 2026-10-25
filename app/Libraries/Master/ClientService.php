<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\DbBool;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

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

        return Transaction::run($this->db, function () use ($data, $values, $now): int {
            $columns = array_keys($data);
            $sql     = 'INSERT INTO clients (' . implode(', ', $columns) . ', created_at, updated_at) VALUES ('
                . implode(', ', array_map(static fn (string $c): string => self::placeholder($c), $columns))
                . ', :now:, :now:) RETURNING id';
            $id = (int) $this->db->query($sql, $data + ['now' => $now])->getRow()->id;

            $this->audit->record('client', $id, 'create', null, ['id' => $id] + $this->auditValues($values));

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $values normalizovane hodnoty
     */
    public function update(int $id, array $values): bool
    {
        $after   = $this->auditValues($values);
        $data    = $this->dbValues($values);
        $now     = service('clock')->now()->format('Y-m-d H:i:sP');
        $assigns = array_map(static fn (string $c): string => $c . ' = ' . self::placeholder($c), array_keys($data));

        return Transaction::run($this->db, function () use ($id, $data, $assigns, $now, $after): bool {
            // Audit "before" z uzamknutého riadku v tej istej transakcii (WR-02).
            $row = $this->db->query('SELECT * FROM clients WHERE id = :id: FOR UPDATE', ['id' => $id])->getRowArray();

            if ($row === null) {
                throw new InvalidArgumentException('Client ' . $id . ' not found');
            }

            $before = $this->auditValues($this->hydrate($row));

            if ($after === $before) {
                return false;
            }

            $this->db->query(
                'UPDATE clients SET ' . implode(', ', $assigns) . ', updated_at = :now: WHERE id = :id:',
                $data + ['now' => $now, 'id' => $id],
            );

            return $this->audit->recordChange('client', $id, 'update', $before, $after);
        });
    }

    public function archive(int $id): bool
    {
        return $this->setArchived($id, true);
    }

    public function unarchive(int $id): bool
    {
        return $this->setArchived($id, false);
    }

    private function setArchived(int $id, bool $archive): bool
    {
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($id, $archive, $now): bool {
            // Skutočná pôvodná hodnota z uzamknutého riadku (WR-03), v rovnakom formáte ako $now.
            $row = $this->db->query(
                "SELECT to_char(archived_at, 'YYYY-MM-DD HH24:MI:SSTZH:TZM') AS archived_at FROM clients WHERE id = :id: FOR UPDATE",
                ['id' => $id],
            )->getRow();

            if ($row === null || ($row->archived_at === null) !== $archive) {
                return false;
            }

            $previous = $row->archived_at === null ? null : (string) $row->archived_at;
            $sql      = $archive
                ? 'UPDATE clients SET archived_at = :now:, updated_at = :now: WHERE id = :id: AND archived_at IS NULL'
                : 'UPDATE clients SET archived_at = NULL, updated_at = :now: WHERE id = :id: AND archived_at IS NOT NULL';
            $this->db->query($sql, ['now' => $now, 'id' => $id]);

            $this->audit->record(
                'client',
                $id,
                $archive ? 'archive' : 'unarchive',
                ['archived_at' => $previous],
                ['archived_at' => $archive ? $now : null],
            );

            return true;
        });
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
