<?php

declare(strict_types=1);

namespace App\Libraries\Work;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\PortalUrlTemplate;
use App\Libraries\Platform\Hours;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

/**
 * Uloha (TSK-01, TSK-02). Prvy prikaz kazdej operacie nad ulohou je SELECT ... FOR UPDATE na jej riadok
 * (poradie zamkov: uloha, potom casti). Zmeny su v audit_log v tej istej transakcii (D-13).
 */
final class TaskService
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
     * @param array<string, mixed> $values normalizovane hodnoty (TaskInput::normalize)
     */
    public function create(array $values, ?string $importKey = null): int
    {
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($values, $importKey, $now): int {
            $client = $this->db->query(
                'SELECT id, portal_url_template FROM clients WHERE id = :id: AND archived_at IS NULL FOR SHARE',
                ['id' => (int) $values['client_id']],
            )->getRow();

            if ($client === null) {
                throw new TaskRejected('client_unavailable');
            }

            $url = $this->expandUrl($values['url'] ?? null, $client->portal_url_template === null ? null : (string) $client->portal_url_template);

            $data = [
                'client_id'  => (int) $client->id,
                'url'        => $url,
                'title'      => (string) $values['title'],
                'hours'      => (string) $values['hours'],
                'rate'       => (string) $values['rate'],
                'note'       => $this->nullable($values['note'] ?? null),
                'import_key' => $importKey,
                'now'        => $now,
            ];

            $id = (int) $this->db->query(
                "INSERT INTO tasks (client_id, url, title, hours, rate, note, status, import_key, created_at, updated_at)
                 VALUES (:client_id:, :url:, :title:, :hours:, :rate:, :note:, 'in_progress', :import_key:, :now:, :now:) RETURNING id",
                $data,
            )->getRow()->id;

            $this->audit->record('task', $id, 'create', null, [
                'client_id'  => $data['client_id'],
                'url'        => $url,
                'title'      => $data['title'],
                'hours'      => $data['hours'],
                'rate'       => $data['rate'],
                'note'       => $data['note'],
                'status'     => 'in_progress',
                'import_key' => $importKey,
            ]);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $values normalizovane hodnoty
     */
    public function update(int $id, array $values): bool
    {
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($id, $values, $now): bool {
            $row = $this->lock($id);

            $clientId = (int) $values['client_id'];
            $after    = [
                'client_id' => $clientId,
                'url'       => $this->nullable($values['url'] ?? null),
                'title'     => (string) $values['title'],
                'hours'     => (string) $values['hours'],
                'rate'      => (string) $values['rate'],
                'note'      => $this->nullable($values['note'] ?? null),
            ];
            $before = $this->editable($row);

            if ($after === $before) {
                return false;
            }

            if ($clientId !== (int) $row['client_id']) {
                $client = $this->db->query(
                    'SELECT id FROM clients WHERE id = :id: AND archived_at IS NULL FOR SHARE',
                    ['id' => $clientId],
                )->getRow();

                if ($client === null) {
                    throw new TaskRejected('client_unavailable');
                }
            }

            // Kontrola casti je v tom istom UPDATE (klient sa nezmeni, ak uloha ma akukolvek cast).
            $this->db->query(
                'UPDATE tasks SET client_id = :client_id:, url = :url:, title = :title:, hours = :hours:, rate = :rate:, note = :note:, updated_at = :now:
                 WHERE id = :id: AND (client_id = :client_id: OR NOT EXISTS (SELECT 1 FROM billing_allocations WHERE task_id = :id:))',
                $after + ['now' => $now, 'id' => $id],
            );

            if ($this->db->affectedRows() !== 1) {
                throw new TaskRejected('client_locked');
            }

            return $this->audit->recordChange('task', $id, 'update', $before, $after);
        });
    }

    public function delete(int $id): void
    {
        Transaction::run($this->db, function () use ($id): void {
            $row    = $this->lock($id);
            $before = $this->editable($row) + ['status' => (string) $row['status']];

            $unlocked = $this->db->query(
                'SELECT id, hours, billing_month, rate, amount, currency FROM billing_allocations WHERE task_id = :id: AND invoice_id IS NULL ORDER BY id FOR UPDATE',
                ['id' => $id],
            )->getResultArray();

            $this->db->query('DELETE FROM billing_allocations WHERE task_id = :id: AND invoice_id IS NULL', ['id' => $id]);

            foreach ($unlocked as $alloc) {
                $this->audit->record('billing_allocation', (int) $alloc['id'], 'delete', [
                    'task_id'       => $id,
                    'hours'         => (string) $alloc['hours'],
                    'billing_month' => (string) $alloc['billing_month'],
                    'rate'          => (string) $alloc['rate'],
                    'amount'        => (string) $alloc['amount'],
                    'currency'      => trim((string) $alloc['currency']),
                ], null);
            }

            $this->db->query(
                'DELETE FROM tasks WHERE id = :id: AND NOT EXISTS (SELECT 1 FROM billing_allocations WHERE task_id = tasks.id AND invoice_id IS NOT NULL)',
                ['id' => $id],
            );

            if ($this->db->affectedRows() !== 1) {
                // Vynimka vrati transakciu: nezamknute casti aj audit ostanu nedotknute.
                throw new TaskHasLockedAllocations($id);
            }

            $this->audit->record('task', $id, 'delete', $before, null);
        });
    }

    /**
     * Zostatok = nacenene hodiny minus vsetky existujuce casti, nikdy pod 0. Bez zamku, na predvyplnenie panelu (D-11).
     */
    public function remainingHours(int $id): string
    {
        $row = $this->db->query(
            'SELECT t.hours, COALESCE((SELECT SUM(a.hours) FROM billing_allocations a WHERE a.task_id = t.id), 0) AS allocated FROM tasks t WHERE t.id = :id:',
            ['id' => $id],
        )->getRowArray();

        if ($row === null) {
            throw new InvalidArgumentException('Task ' . $id . ' not found');
        }

        return Hours::remaining(Hours::plus((string) $row['hours'], '0'), Hours::plus((string) $row['allocated'], '0'));
    }

    /**
     * Oznaci ulohu ako zapracovanu: stav, cast v mesiaci a audit v jednej transakcii (D-11, D-13).
     * Pri 0 h sa nova cast nevytvori. Vracia id novej casti alebo null.
     */
    public function markDone(int $id, string $month, string $hours): ?int
    {
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($id, $month, $hours, $now): ?int {
            $row = $this->lock($id);

            if ((string) $row['status'] === 'done') {
                throw new TaskRejected('already_done');
            }

            $this->db->query(
                "UPDATE tasks SET status = 'done', done_at = :now:, updated_at = :now: WHERE id = :id: AND status = 'in_progress'",
                ['now' => $now, 'id' => $id],
            );

            if ($this->db->affectedRows() !== 1) {
                throw new TaskRejected('already_done');
            }

            $allocationId = null;

            if (Hours::isPositive($hours)) {
                $allocationId = (new AllocationService($this->db, $this->audit))->add($id, ['hours' => $hours, 'billing_month' => $month]);
            }

            $this->audit->record('task', $id, 'mark_done', ['status' => 'in_progress', 'done_at' => null], [
                'status'        => 'done',
                'done_at'       => $now,
                'allocation_id' => $allocationId,
                'billing_month' => $month,
                'hours'         => $hours,
            ]);

            return $allocationId;
        });
    }

    /**
     * Vrati zapracovanu ulohu do stavu V praci; casti ostavaju nezmenene.
     */
    public function reopen(int $id): void
    {
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        Transaction::run($this->db, function () use ($id, $now): void {
            $row = $this->lock($id);

            if ((string) $row['status'] !== 'done') {
                throw new TaskRejected('not_done');
            }

            $this->db->query(
                "UPDATE tasks SET status = 'in_progress', done_at = NULL, updated_at = :now: WHERE id = :id: AND status = 'done'",
                ['now' => $now, 'id' => $id],
            );

            if ($this->db->affectedRows() !== 1) {
                throw new TaskRejected('not_done');
            }

            $this->audit->record(
                'task',
                $id,
                'reopen',
                ['status' => 'done', 'done_at' => $row['done_at'] === null ? null : (string) $row['done_at']],
                ['status' => 'in_progress', 'done_at' => null],
            );
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->query('SELECT * FROM tasks WHERE id = :id:', ['id' => $id])->getRowArray();

        if ($row === null) {
            return null;
        }

        $row['id']        = (int) $row['id'];
        $row['client_id'] = (int) $row['client_id'];
        $row['hours']     = (string) $row['hours'];
        $row['rate']      = (string) $row['rate'];

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function lock(int $id): array
    {
        $row = $this->db->query('SELECT * FROM tasks WHERE id = :id: FOR UPDATE', ['id' => $id])->getRowArray();

        if ($row === null) {
            throw new InvalidArgumentException('Task ' . $id . ' not found');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function editable(array $row): array
    {
        return [
            'client_id' => (int) $row['client_id'],
            'url'       => $this->nullable($row['url']),
            'title'     => (string) $row['title'],
            'hours'     => (string) $row['hours'],
            'rate'      => (string) $row['rate'],
            'note'      => $this->nullable($row['note']),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function expandUrl(mixed $url, ?string $template): ?string
    {
        $url = $this->nullable($url);

        if ($url !== null && $template !== null && preg_match('/^\d{1,20}$/D', $url) === 1) {
            return PortalUrlTemplate::render($template, $url);
        }

        return $url;
    }
}
