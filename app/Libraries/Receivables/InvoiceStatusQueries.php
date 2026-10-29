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
