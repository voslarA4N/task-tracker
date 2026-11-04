<?php

declare(strict_types=1);

namespace App\Libraries\Audit;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Čítanie denníka zmien (AUD-02, D-13). Iba SELECT, tabuľka je insert-only.
 */
final class AuditQueries
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
     * @return array{rows: list<array<string, mixed>>, hasMore: bool}
     */
    public function list(AuditFilter $f, int $perPage = 50): array
    {
        $where = [];
        $binds = [];
        $tz    = config('App')->appTimezone;

        if ($f->entityType !== null) {
            $where[]     = 'a.entity_type = :et:';
            $binds['et'] = $f->entityType;
        }

        if ($f->action !== null) {
            $where[]     = 'a.action = :ac:';
            $binds['ac'] = $f->action;
        }

        if ($f->userId !== null) {
            $where[]    = 'a.user_id = :u:';
            $binds['u'] = $f->userId;
        }

        if ($f->from !== null) {
            $where[]       = 'a.occurred_at >= (CAST(:from: AS date)::timestamp AT TIME ZONE :tz:)';
            $binds['from'] = $f->from;
            $binds['tz']   = $tz;
        }

        if ($f->to !== null) {
            $where[]     = 'a.occurred_at < ((CAST(:to: AS date) + 1)::timestamp AT TIME ZONE :tz:)';
            $binds['to'] = $f->to;
            $binds['tz'] = $tz;
        }

        $binds['n'] = $perPage + 1;
        $binds['o'] = ($f->page - 1) * $perPage;

        $sql = 'SELECT a.id, a.occurred_at, a.user_id, a.entity_type, a.entity_id, a.action, a.ip,
                       COALESCE(ai.secret, u.username) AS user_label,
                       (SELECT count(*) FROM jsonb_object_keys(COALESCE(a.after_data, a.before_data, \'{}\'::jsonb))) AS changed_count
                FROM audit_log a
                LEFT JOIN users u ON u.id = a.user_id
                LEFT JOIN auth_identities ai ON ai.user_id = u.id AND ai.type = \'email_password\''
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY a.occurred_at DESC, a.id DESC LIMIT :n: OFFSET :o:';

        /** @var list<array<string, mixed>> $rows */
        $rows    = $this->db->query($sql, $binds)->getResultArray();
        $hasMore = count($rows) > $perPage;

        if ($hasMore) {
            $rows = array_slice($rows, 0, $perPage);
        }

        return ['rows' => $rows, 'hasMore' => $hasMore];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->query(
            'SELECT a.id, a.occurred_at, a.user_id, a.entity_type, a.entity_id, a.action, a.ip, a.before_data, a.after_data,
                    COALESCE(ai.secret, u.username) AS user_label
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN auth_identities ai ON ai.user_id = u.id AND ai.type = \'email_password\'
             WHERE a.id = :id:',
            ['id' => $id],
        )->getRowArray();

        if ($row === null) {
            return null;
        }

        foreach (['before_data', 'after_data'] as $k) {
            $row[$k] = is_string($row[$k] ?? null) ? json_decode($row[$k], true, 512, JSON_THROW_ON_ERROR) : null;
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    public function entityTypes(): array
    {
        return $this->column('SELECT DISTINCT entity_type AS v FROM audit_log ORDER BY entity_type');
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->column('SELECT DISTINCT action AS v FROM audit_log ORDER BY action');
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function users(): array
    {
        $rows = $this->db->query(
            'SELECT u.id, COALESCE(ai.secret, u.username, \'#\' || u.id::text) AS label
             FROM users u
             LEFT JOIN auth_identities ai ON ai.user_id = u.id AND ai.type = \'email_password\'
             WHERE u.deleted_at IS NULL
             ORDER BY label',
        )->getResultArray();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => (string) $r['label']], $rows);
    }

    /**
     * @return list<string>
     */
    private function column(string $sql): array
    {
        return array_map(static fn (array $r): string => (string) $r['v'], $this->db->query($sql)->getResultArray());
    }
}
