<?php

declare(strict_types=1);

namespace App\Libraries\Work;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\Money;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use InvalidArgumentException;
use Throwable;

/**
 * Fakturacne casti ulohy (ALC-01, ALC-02, ALC-05). Nezamknutu cast mozno pridat, upravit, presunut a zmazat;
 * zamknutu (invoice_id NOT NULL) nie: podmienka je v tom istom SQL prikaze (D-09), trigger je posledna linia.
 */
final class AllocationService
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
     * @param array<string, mixed> $values hours, billing_month, volitelne rate (normalizovane)
     */
    public function add(int $taskId, array $values): int
    {
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($taskId, $values, $now): int {
            // WR-06: FOR SHARE na klienta blokuje subeznu zmenu meny (ClientService::update drzi FOR UPDATE),
            // takze cast sa nikdy neulozi v mene, ktoru klient prave opusta.
            $task = $this->db->query(
                'SELECT t.id, t.rate, c.currency FROM tasks t JOIN clients c ON c.id = t.client_id WHERE t.id = :id: FOR UPDATE OF t FOR SHARE OF c',
                ['id' => $taskId],
            )->getRowArray();

            if ($task === null) {
                throw new InvalidArgumentException('Task ' . $taskId . ' not found');
            }

            $hours  = (string) $values['hours'];
            $rate   = (string) ($values['rate'] ?? $task['rate']);
            $amount = Money::of($rate)->timesHours($hours)->toDecimalString();
            $row    = [
                'task_id'       => $taskId,
                'hours'         => $hours,
                'billing_month' => (string) $values['billing_month'],
                'rate'          => Money::of($rate)->toDecimalString(),
                'amount'        => $amount,
                'currency'      => trim((string) $task['currency']),
            ];

            $id = (int) $this->db->query(
                'INSERT INTO billing_allocations (task_id, hours, billing_month, rate, amount, currency, created_at, updated_at)
                 VALUES (:task:, :hours:, :month:, :rate:, :amount:, :currency:, :now:, :now:) RETURNING id',
                [
                    'task'     => $taskId,
                    'hours'    => $row['hours'],
                    'month'    => $row['billing_month'],
                    'rate'     => $row['rate'],
                    'amount'   => $amount,
                    'currency' => $row['currency'],
                    'now'      => $now,
                ],
            )->getRow()->id;

            $this->audit->record('billing_allocation', $id, 'create', null, $row);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $values hours, billing_month, volitelne rate
     *
     * @throws AllocationLocked
     */
    public function update(int $id, array $values): bool
    {
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        try {
            return Transaction::run($this->db, function () use ($id, $values, $now): bool {
                $before = $this->lockRow($id);

                $hours  = (string) $values['hours'];
                $month  = (string) $values['billing_month'];
                $rate   = Money::of((string) ($values['rate'] ?? $before['rate']))->toDecimalString();
                $amount = Money::of($rate)->timesHours($hours)->toDecimalString();
                $after  = ['hours' => $hours, 'billing_month' => $month, 'rate' => $rate, 'amount' => $amount];

                $current = array_intersect_key($before, $after);

                if ($current === $after) {
                    return false;
                }

                $this->db->query(
                    'UPDATE billing_allocations SET hours = :hours:, billing_month = :month:, rate = :rate:, amount = :amount:, updated_at = :now:
                     WHERE id = :id: AND invoice_id IS NULL',
                    ['hours' => $hours, 'month' => $month, 'rate' => $rate, 'amount' => $amount, 'now' => $now, 'id' => $id],
                );

                if ($this->db->affectedRows() !== 1) {
                    throw new AllocationLocked($id);
                }

                return $this->audit->recordChange('billing_allocation', $id, 'update', $current, $after);
            });
        } catch (DatabaseException $e) {
            throw $this->translate($e, $id);
        }
    }

    /**
     * Presun casti do ineho fakturacneho mesiaca. Meni iba billing_month: hodiny, sadzba a suma ostavaju tak,
     * ako su ulozene pod zamkom (ziadny lost update zo starsieho citania, ziadny prepocet sumy).
     *
     * @throws AllocationLocked
     */
    public function moveMonth(int $id, string $month): bool
    {
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        try {
            return Transaction::run($this->db, function () use ($id, $month, $now): bool {
                $before = $this->lockRow($id);

                if ($before['invoice_id'] !== null) {
                    throw new AllocationLocked($id);
                }

                if ($before['billing_month'] === $month) {
                    return false;
                }

                $this->db->query(
                    'UPDATE billing_allocations SET billing_month = :month:, updated_at = :now: WHERE id = :id: AND invoice_id IS NULL',
                    ['month' => $month, 'now' => $now, 'id' => $id],
                );

                if ($this->db->affectedRows() !== 1) {
                    throw new AllocationLocked($id);
                }

                return $this->audit->recordChange('billing_allocation', $id, 'update', ['billing_month' => $before['billing_month']], ['billing_month' => $month]);
            });
        } catch (DatabaseException $e) {
            throw $this->translate($e, $id);
        }
    }

    /**
     * @throws AllocationLocked
     */
    public function delete(int $id): void
    {
        try {
            Transaction::run($this->db, function () use ($id): void {
                $before = $this->lockRow($id);

                $this->db->query('DELETE FROM billing_allocations WHERE id = :id: AND invoice_id IS NULL', ['id' => $id]);

                if ($this->db->affectedRows() !== 1) {
                    throw new AllocationLocked($id);
                }

                $this->audit->record('billing_allocation', $id, 'delete', $before, null);
            });
        } catch (DatabaseException $e) {
            throw $this->translate($e, $id);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->query('SELECT * FROM billing_allocations WHERE id = :id:', ['id' => $id])->getRowArray();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForTask(int $taskId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM billing_allocations WHERE task_id = :task: ORDER BY billing_month, id',
            ['task' => $taskId],
        )->getResultArray();

        return array_map(fn (array $r): array => $this->hydrate($r), $rows);
    }

    /**
     * Poradie zamkov: uloha -> cast. Vrati hydratovany riadok casti.
     *
     * @return array<string, mixed>
     */
    private function lockRow(int $id): array
    {
        $head = $this->db->query('SELECT task_id FROM billing_allocations WHERE id = :id:', ['id' => $id])->getRowArray();

        if ($head === null) {
            throw new InvalidArgumentException('Allocation ' . $id . ' not found');
        }

        $this->db->query('SELECT id FROM tasks WHERE id = :task: FOR UPDATE', ['task' => (int) $head['task_id']]);
        $row = $this->db->query('SELECT * FROM billing_allocations WHERE id = :id: FOR UPDATE', ['id' => $id])->getRowArray();

        if ($row === null) {
            throw new InvalidArgumentException('Allocation ' . $id . ' not found');
        }

        return $this->hydrate($row);
    }

    private function translate(DatabaseException $e, int $id): Throwable
    {
        return str_contains($e->getMessage(), 'is locked') ? new AllocationLocked($id) : $e;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'task_id'       => (int) $row['task_id'],
            'hours'         => (string) $row['hours'],
            'billing_month' => trim((string) $row['billing_month']),
            'rate'          => (string) $row['rate'],
            'amount'        => (string) $row['amount'],
            'currency'      => trim((string) $row['currency']),
            'invoice_id'    => $row['invoice_id'] === null ? null : (int) $row['invoice_id'],
        ];
    }
}
