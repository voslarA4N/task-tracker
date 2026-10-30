<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Delivery\InvoiceMailer;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Citacia vrstva faktur: zoznam a detail. Nikdy nenacitava bajty PDF, detail je zo snapshotu a strukturovanych stlpcov.
 */
final class InvoiceQueries
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
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public function list(int $limit = 100, ?string $status = null, ?string $today = null): array
    {
        $today ??= service('clock')->today();
        $cond  = "(i.origin = 'issued' AND i.type = 'invoice' AND i.balance > 0 AND i.due_date < CAST(:today: AS date))";
        $where = '';

        if (in_array($status, ['issued', 'sent', 'partially_paid', 'paid'], true)) {
            $where = ' WHERE i.status = :status:';
        } elseif ($status === 'overdue') {
            $where = ' WHERE ' . $cond;
        }

        $binds = ['today' => $today, 'n' => $limit + 1];

        if (str_contains($where, ':status:')) {
            $binds['status'] = $status;
        }

        $rows = $this->db->query(
            'SELECT i.id, i.origin, i.number, i.external_ref, i.issue_date, i.billing_month, i.currency, i.total, i.created_at, c.name AS client_name,
                    i.status, i.balance, i.paid_amount, i.due_date,
                    f.fx_rate, f.total_entity_ccy, f.snapshot->\'fx\'->>\'entity_currency\' AS entity_currency,
                    CASE WHEN ' . $cond . ' THEN 1 ELSE 0 END AS overdue,
                    CASE WHEN ' . $cond . ' THEN CAST(:today: AS date) - i.due_date ELSE 0 END AS days_overdue
             FROM invoice_status_v i JOIN clients c ON c.id = i.client_id JOIN invoices f ON f.id = i.id' . $where . '
             ORDER BY i.issue_date DESC NULLS LAST, i.number DESC NULLS LAST, i.id DESC
             LIMIT :n:',
            $binds,
        )->getResultArray();

        $truncated = count($rows) > $limit;

        return [
            'rows' => array_map(static fn (array $r): array => [
                'id'           => (int) $r['id'],
                'currency'     => trim((string) $r['currency']),
                'overdue'      => (int) $r['overdue'] === 1,
                'days_overdue' => (int) $r['days_overdue'],
            ] + $r, array_slice($rows, 0, $limit)),
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(int $id): ?array
    {
        $invoice = $this->db->query(
            'SELECT i.id, i.origin, i.type, i.number, i.external_ref, i.client_id, i.billing_month, i.issue_date, i.delivery_date, i.due_date,
                    i.currency, i.total, i.line_count, i.doc_language, i.vat_regime, i.exemption_code, i.payment_method,
                    i.template_version, i.snapshot_schema, i.snapshot, i.created_at, c.name AS client_name,
                    i.total_entity_ccy, i.fx_rate, i.fx_source, i.fx_date, i.fx_table_no, i.fx_date_basis
             FROM invoices i JOIN clients c ON c.id = i.client_id
             WHERE i.id = :id:',
            ['id' => $id],
        )->getRowArray();

        if ($invoice === null) {
            return null;
        }

        $invoice['currency']      = trim((string) $invoice['currency']);
        $invoice['billing_month'] = trim((string) $invoice['billing_month']);
        $invoice['snapshot']      = json_decode((string) $invoice['snapshot'], true, 512, JSON_THROW_ON_ERROR);

        $lines = $this->db->query(
            'SELECT l.position, l.source_task_id, l.title, l.task_ref, l.portal_url, l.estimated_hours, l.hours, l.rate, l.amount, l.vat_code,
                    (l.source_task_id IS NOT NULL AND EXISTS (SELECT 1 FROM tasks t WHERE t.id = l.source_task_id)) AS task_exists
             FROM invoice_lines l WHERE l.invoice_id = :id: ORDER BY l.position',
            ['id' => $id],
        )->getResultArray();

        $invoice['lines'] = array_map(static function (array $l): array {
            $l['task_exists'] = in_array($l['task_exists'], [true, 't', '1', 1], true);

            return $l;
        }, $lines);

        $st = $this->db->query('SELECT status, sent_at, send_count, last_reminder_at FROM invoice_status_v WHERE id = :id:', ['id' => $id])->getRowArray() ?? [];

        $invoice['status']           = (string) ($st['status'] ?? 'issued');
        $invoice['sent_at']          = $st['sent_at'] ?? null;
        $invoice['send_count']       = (int) ($st['send_count'] ?? 0);
        $invoice['last_reminder_at'] = $st['last_reminder_at'] ?? null;
        $invoice['history']          = $invoice['origin'] === 'external' ? [] : (new InvoiceMailer($this->db))->history($id);

        $store                      = new DocumentStore($this->db);
        $invoice['document']        = $store->meta($id);
        $invoice['report_document'] = $store->meta($id, 'report');

        return $invoice;
    }
}
