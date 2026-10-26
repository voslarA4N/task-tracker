<?php

declare(strict_types=1);

namespace App\Libraries\Work;

use App\Libraries\Platform\Hours;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

/**
 * Citacia vrstva uloh: zoznam, detail, dashboard. Agregacie v SQL nad task_billing_v, bez N+1.
 */
final class TaskQueries
{
    /**
     * Poradie je zaroven priorita skupin (WR-01).
     */
    public const DASHBOARD_GROUPS = ['done_due', 'partial', 'in_progress'];

    private const GROUP_LIMIT = 10;
    private const ROW_COLUMNS = <<<'SQL'
        t.id, t.title, t.url, t.client_id, c.name AS client_name, c.currency,
        t.hours, t.rate, round(t.hours * t.rate, 2) AS quoted_amount,
        t.status, t.done_at, t.created_at,
        v.billing_state, v.billed_hours, v.planned_hours, v.allocated_hours, v.locked_count, v.unlocked_count
        SQL;
    private const ROW_FROM = <<<'SQL'
        FROM tasks t
        JOIN clients c ON c.id = t.client_id
        JOIN task_billing_v v ON v.task_id = t.id
        SQL;

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
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public function list(TaskFilter $f, int $limit = 200): array
    {
        $where  = [];
        $params = ['limit' => $limit + 1];

        $where[] = match ($f->state) {
            'current'     => "(t.status <> 'done' OR v.unlocked_count > 0)",
            'in_progress' => "t.status = 'in_progress'",
            'done'        => "(t.status = 'done' AND v.locked_count = 0)",
            'partial'     => "v.billing_state = 'partial'",
            'billed'      => "v.billing_state = 'billed'",
            default       => 'TRUE',
        };

        if ($f->clientId !== null) {
            $where[]          = 't.client_id = :client:';
            $params['client'] = $f->clientId;
        }

        if ($f->month !== null) {
            $where[]         = 'EXISTS (SELECT 1 FROM billing_allocations a WHERE a.task_id = t.id AND a.billing_month = :month:)';
            $params['month'] = $f->month;
        }

        if ($f->q !== null) {
            $where[]     = "(t.title ILIKE :q: ESCAPE '\\' OR t.url ILIKE :q: ESCAPE '\\' OR t.note ILIKE :q: ESCAPE '\\')";
            $params['q'] = '%' . self::escapeLike($f->q) . '%';
        }

        $sql = 'SELECT ' . self::ROW_COLUMNS . ' ' . self::ROW_FROM
            . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY (t.status = 'done' AND v.billing_state <> 'partial'), t.created_at DESC, t.id DESC LIMIT :limit:";

        $rows = array_map($this->decorate(...), $this->db->query($sql, $params)->getResultArray());

        $truncated = count($rows) > $limit;

        return ['rows' => array_slice($rows, 0, $limit), 'truncated' => $truncated];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(int $id): ?array
    {
        $task = $this->db->query(
            'SELECT t.id, t.client_id, t.url, t.title, t.hours, t.rate, t.note, t.status, t.done_at, t.created_at, t.updated_at,
                    round(t.hours * t.rate, 2) AS quoted_amount,
                    c.name AS client_name, c.currency, c.portal_name, (c.archived_at IS NOT NULL) AS client_archived,
                    v.billing_state, v.billed_hours, v.planned_hours, v.allocated_hours, v.locked_count, v.unlocked_count,
                    v.billed_amount, v.planned_amount
             FROM tasks t
             JOIN clients c ON c.id = t.client_id
             JOIN task_billing_v v ON v.task_id = t.id
             WHERE t.id = :id:',
            ['id' => $id],
        )->getRowArray();

        if ($task === null) {
            return null;
        }

        $allocations = $this->db->query(
            'SELECT id, hours, billing_month, rate, amount, currency, invoice_id, (invoice_id IS NOT NULL) AS locked
             FROM billing_allocations WHERE task_id = :id: ORDER BY billing_month, id',
            ['id' => $id],
        )->getResultArray();

        $task['currency']        = trim((string) $task['currency']);
        $task['client_archived'] = self::bool($task['client_archived']);
        $task['allocations']     = array_map(static function (array $a): array {
            $a['locked']   = self::bool($a['locked']);
            $a['currency'] = trim((string) $a['currency']);

            return $a;
        }, $allocations);

        $quoted    = (string) $task['hours'];
        $allocated = (string) $task['allocated_hours'];

        $task['remaining']   = Hours::remaining($quoted, $allocated);
        $task['delta']       = Hours::delta($allocated, $quoted);
        $task['over_quoted'] = Hours::compare($allocated, $quoted) > 0;
        $task['has_locked']  = (int) $task['locked_count'] > 0;
        $task['billed_pct']  = Hours::percentOf((string) $task['billed_hours'], $quoted);
        $task['planned_pct'] = $this->plannedPct($task);

        return $task;
    }

    /**
     * @return array{
     *     due: list<array{currency: string, amount: string, hours: string}>,
     *     wip: list<array{currency: string, amount: string, hours: string}>,
     *     groups: array<string, array{rows: list<array<string, mixed>>, total: int}>
     * }
     */
    public function dashboard(string $month, ?int $clientId): array
    {
        $clientSql    = $clientId !== null ? ' AND t.client_id = :client:' : '';
        $clientParams = $clientId !== null ? ['client' => $clientId] : [];

        $due = $this->db->query(
            'SELECT a.currency, SUM(a.amount) AS amount, SUM(a.hours) AS hours
             FROM billing_allocations a JOIN tasks t ON t.id = a.task_id
             WHERE a.invoice_id IS NULL AND a.billing_month = :month:' . $clientSql . '
             GROUP BY a.currency ORDER BY a.currency',
            ['month' => $month] + $clientParams,
        )->getResultArray();

        $wip = $this->db->query(
            "SELECT c.currency, SUM(round(t.hours * t.rate, 2)) AS amount, SUM(t.hours) AS hours
             FROM tasks t JOIN clients c ON c.id = t.client_id
             WHERE t.status = 'in_progress'" . $clientSql . '
             GROUP BY c.currency ORDER BY c.currency',
            $clientParams,
        )->getResultArray();

        $groups = [];

        foreach (self::DASHBOARD_GROUPS as $key) {
            $groups[$key] = $this->group(self::groupCondition($key), ['month' => $month] + $clientParams, $clientSql);
        }

        $money = static fn (array $r): array => [
            'currency' => trim((string) $r['currency']),
            'amount'   => (string) $r['amount'],
            'hours'    => (string) $r['hours'],
        ];

        return ['due' => array_map($money, $due), 'wip' => array_map($money, $wip), 'groups' => $groups];
    }

    /**
     * WR-01: skupiny dashboardu su disjunktne s pevnou prioritou (kazda uloha je v najviac jednej skupine):
     * 1. Zapracovane, do faktury (done s nezamknutou castou vybraneho mesiaca :month:),
     * 2. Ciastocne vyfakturovane (partial, ktora nie je v skupine 1),
     * 3. V praci (in_progress, ktora nie je ciastocne vyfakturovana).
     * 'due' je mnozina uloh za dlazdicou Na fakturaciu (nezamknuta cast v mesiaci, bez ohladu na stav).
     */
    public static function groupCondition(string $group): string
    {
        $due = 'EXISTS (SELECT 1 FROM billing_allocations a WHERE a.task_id = t.id AND a.invoice_id IS NULL AND a.billing_month = :month:)';

        return match ($group) {
            'due'         => $due,
            'done_due'    => "t.status = 'done' AND " . $due,
            'partial'     => "v.billing_state = 'partial' AND NOT (t.status = 'done' AND " . $due . ')',
            'in_progress' => "t.status = 'in_progress' AND v.billing_state <> 'partial'",
            default       => throw new InvalidArgumentException('Unknown dashboard group ' . $group),
        };
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function group(string $condition, array $params, string $clientSql): array
    {
        $params['limit'] = self::GROUP_LIMIT;
        $result          = $this->db->query(
            'SELECT ' . self::ROW_COLUMNS . ', COUNT(*) OVER () AS total_count ' . self::ROW_FROM
            . ' WHERE ' . $condition . $clientSql
            . ' ORDER BY t.created_at DESC, t.id DESC LIMIT :limit:',
            $params,
        )->getResultArray();

        $total = $result === [] ? 0 : (int) $result[0]['total_count'];
        $rows  = array_map(function (array $r): array {
            unset($r['total_count']);

            return $this->decorate($r);
        }, $result);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decorate(array $row): array
    {
        $row['currency']    = trim((string) $row['currency']);
        $row['remaining']   = Hours::remaining((string) $row['hours'], (string) $row['allocated_hours']);
        $row['billed_pct']  = Hours::percentOf((string) $row['billed_hours'], (string) $row['hours']);
        $row['planned_pct'] = $this->plannedPct($row);

        return $row;
    }

    /**
     * Planovane percento orezane tak, aby billed + planned nepresiahlo 100.
     *
     * @param array<string, mixed> $row
     */
    private function plannedPct(array $row): int
    {
        $planned = Hours::percentOf((string) $row['planned_hours'], (string) $row['hours']);

        return min($planned, 100 - Hours::percentOf((string) $row['billed_hours'], (string) $row['hours']));
    }

    private static function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1';
    }

    private static function escapeLike(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q);
    }
}
