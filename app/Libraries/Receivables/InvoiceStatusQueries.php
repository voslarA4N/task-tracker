<?php

declare(strict_types=1);

namespace App\Libraries\Receivables;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Stav faktury (odoslana, uhradena, po splatnosti) a zoznam uhrad. Den "dnes" dodava volajuci z Clock
 * (Europe/Bratislava), nikdy cas databazoveho servera (D-22).
 */
final class InvoiceStatusQueries
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
     * @return array{status: string, sent_at: ?string, send_count: int, last_reminder_at: ?string, paid_amount: string, balance: string, last_paid_on: ?string, due_date: ?string, total: string, currency: string, overdue: bool, days_overdue: int}|null
     */
    public function forInvoice(int $id, string $today): ?array
    {
        $row = $this->db->query(
            "SELECT status, sent_at, send_count, last_reminder_at, paid_amount, balance, last_paid_on, due_date, total, currency,
                    CASE WHEN origin = 'issued' AND type = 'invoice' AND balance > 0 AND due_date < CAST(:today: AS date) THEN 1 ELSE 0 END AS overdue,
                    CASE WHEN origin = 'issued' AND type = 'invoice' AND balance > 0 AND due_date < CAST(:today: AS date)
                         THEN CAST(:today: AS date) - due_date ELSE 0 END AS days_overdue
             FROM invoice_status_v WHERE id = :id:",
            ['today' => $today, 'id' => $id],
        )->getRowArray();

        if ($row === null) {
            return null;
        }

        return [
            'status'           => (string) $row['status'],
            'sent_at'          => $row['sent_at'] === null ? null : (string) $row['sent_at'],
            'send_count'       => (int) $row['send_count'],
            'last_reminder_at' => $row['last_reminder_at'] === null ? null : (string) $row['last_reminder_at'],
            'paid_amount'      => (string) $row['paid_amount'],
            'balance'          => (string) $row['balance'],
            'last_paid_on'     => $row['last_paid_on'] === null ? null : (string) $row['last_paid_on'],
            'due_date'         => $row['due_date'] === null ? null : (string) $row['due_date'],
            'total'            => (string) $row['total'],
            'currency'         => trim((string) $row['currency']),
            'overdue'          => (int) $row['overdue'] === 1,
            'days_overdue'     => (int) $row['days_overdue'],
        ];
    }

    /**
     * Faktury po splatnosti (zostatok > 0, splatnost pred dnom "dnes" z Clock), od najstarsej splatnosti.
     *
     * @return array{rows: list<array{id: int, number: string, client_name: string, due_date: string, days_overdue: int, balance: string, currency: string, status: string, last_reminder_at: ?string}>, total: int}
     */
    public function overdue(string $today, ?int $clientId, int $limit): array
    {
        $binds = ['today' => $today, 'n' => $limit];
        $where = '';

        if ($clientId !== null) {
            $where           = ' AND i.client_id = :client:';
            $binds['client'] = $clientId;
        }

        $rows = $this->db->query(
            "SELECT i.id, i.number, c.name AS client_name, i.due_date, CAST(:today: AS date) - i.due_date AS days_overdue,
                    i.balance, i.currency, i.status, i.last_reminder_at
             FROM invoice_status_v i JOIN clients c ON c.id = i.client_id
             WHERE i.origin = 'issued' AND i.type = 'invoice' AND i.balance > 0 AND i.due_date < CAST(:today: AS date)" . $where . '
             ORDER BY i.due_date ASC, i.id ASC
             LIMIT :n:',
            $binds,
        )->getResultArray();

        return [
            'rows' => array_map(static fn (array $r): array => [
                'id'               => (int) $r['id'],
                'number'           => (string) $r['number'],
                'client_name'      => (string) $r['client_name'],
                'due_date'         => (string) $r['due_date'],
                'days_overdue'     => (int) $r['days_overdue'],
                'balance'          => (string) $r['balance'],
                'currency'         => trim((string) $r['currency']),
                'status'           => (string) $r['status'],
                'last_reminder_at' => $r['last_reminder_at'] === null ? null : (string) $r['last_reminder_at'],
            ], $rows),
            'total' => $this->countOverdue($today, $clientId),
        ];
    }

    public function countOverdue(string $today, ?int $clientId): int
    {
        $binds = ['today' => $today];
        $where = '';

        if ($clientId !== null) {
            $where           = ' AND client_id = :client:';
            $binds['client'] = $clientId;
        }

        $row = $this->db->query(
            "SELECT count(*) AS n FROM invoice_status_v
             WHERE origin = 'issued' AND type = 'invoice' AND balance > 0 AND due_date < CAST(:today: AS date)" . $where,
            $binds,
        )->getRowArray();

        return (int) ($row['n'] ?? 0);
    }

    /**
     * Uhrady od najnovsej, aj stornovane (s dovodom).
     *
     * @return list<array{id: int, paid_on: string, amount: string, note: ?string, created_at: string, voided: bool, void_reason: ?string, voided_at: ?string}>
     */
    public function payments(int $invoiceId): array
    {
        $rows = $this->db->query(
            'SELECT p.id, p.paid_on, p.amount, p.note, p.created_at, v.reason AS void_reason, v.voided_at
             FROM payments p LEFT JOIN payment_voids v ON v.payment_id = p.id
             WHERE p.invoice_id = :i:
             ORDER BY p.paid_on DESC, p.id DESC',
            ['i' => $invoiceId],
        )->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'paid_on'     => (string) $r['paid_on'],
            'amount'      => (string) $r['amount'],
            'note'        => $r['note'] === null ? null : (string) $r['note'],
            'created_at'  => (string) $r['created_at'],
            'voided'      => $r['voided_at'] !== null,
            'void_reason' => $r['void_reason'] === null ? null : (string) $r['void_reason'],
            'voided_at'   => $r['voided_at'] === null ? null : (string) $r['voided_at'],
        ], $rows);
    }
}
