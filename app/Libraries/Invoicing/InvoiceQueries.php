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
    private const SORT_SQL = [
        'number'           => 'i.number',
        'issue_date'       => 'i.issue_date',
        'turnover_date'    => 'f.turnover_date',
        'due_date'         => 'i.due_date',
        'currency'         => 'i.currency',
        'total'            => 'i.total',
        'total_entity_ccy' => 'f.total_entity_ccy',
    ];

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
     * Stlpec radenia; 'client' sa sklada za behu z ClientNameSql (rovnaky vyraz ako zobrazeny nazov, D-01).
     */
    private static function sortColumn(string $sort): string
    {
        if ($sort === 'client') {
            return ClientNameSql::snapshotName('f');
        }

        return self::SORT_SQL[$sort] ?? self::sortColumn(InvoiceFilter::DEFAULT_SORT);
    }

    /**
     * @param int|null $limit null = bez limitu (export), truncated je potom false
     *
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public function list(?int $limit = 100, ?string $status = null, ?string $today = null, ?InvoiceFilter $filter = null): array
    {
        $today ??= service('clock')->today();
        $status ??= $filter?->status;
        $cond  = "(i.origin = 'issued' AND i.type = 'invoice' AND i.status <> 'cancelled' AND i.balance > 0 AND i.due_date < CAST(:today: AS date))";
        $where = [];
        $binds = ['today' => $today];

        if (in_array($status, ['issued', 'sent', 'partially_paid', 'paid', 'cancelled'], true)) {
            $where[]         = "i.type = 'invoice' AND i.status = :status:";
            $binds['status'] = $status;
        } elseif ($status === 'credit_note') {
            $where[] = "i.type = 'credit_note'";
        } elseif ($status === 'overdue') {
            $where[] = $cond;
        }

        if ($filter !== null) {
            if ($filter->entityId !== null) {
                $where[]         = 'f.entity_id = :entity:';
                $binds['entity'] = $filter->entityId;
            }

            if ($filter->clientId !== null) {
                $where[]         = 'i.client_id = :client:';
                $binds['client'] = $filter->clientId;
            }

            if ($filter->q !== null) {
                $where[]    = "(i.number ILIKE :q: ESCAPE '\\' OR i.external_ref ILIKE :q: ESCAPE '\\')";
                $binds['q'] = '%' . self::escapeLike($filter->q) . '%';
            }

            if ($filter->from !== null) {
                $where[]       = 'i.issue_date >= CAST(:from: AS date)';
                $binds['from'] = $filter->from;
            }

            if ($filter->to !== null) {
                $where[]     = 'i.issue_date <= CAST(:to: AS date)';
                $binds['to'] = $filter->to;
            }
        }

        $order = 'i.issue_date DESC NULLS LAST, i.number DESC NULLS LAST, i.id DESC';

        if ($filter !== null && ! $filter->isDefaultSort()) {
            $col   = self::sortColumn($filter->sort);
            $dir   = $filter->dir === 'asc' ? 'ASC' : 'DESC';
            $order = $col . ' ' . $dir . ' NULLS LAST, i.id DESC';
        }

        $limitSql = '';

        if ($limit !== null) {
            $limitSql   = ' LIMIT :n:';
            $binds['n'] = $limit + 1;
        }

        $rows = $this->db->query(
            'SELECT i.id, i.origin, i.number, i.external_ref, i.issue_date, i.billing_month, i.currency, i.total, i.created_at, ' . ClientNameSql::snapshotName('f') . ' AS client_name,
                    i.status, i.balance, i.paid_amount, i.due_date, i.type, i.credit_note_number, i.corrected_number, i.sent_at,
                    f.fx_rate, f.total_entity_ccy, f.snapshot->\'fx\'->>\'entity_currency\' AS entity_currency,
                    f.entity_id, f.turnover_date, f.delivery_date,
                    (SELECT MAX(p.paid_on) FROM payments p WHERE p.invoice_id = i.id AND NOT EXISTS (SELECT 1 FROM payment_voids pv WHERE pv.payment_id = p.id)) AS last_paid_on,
                    CASE WHEN ' . $cond . ' THEN 1 ELSE 0 END AS overdue,
                    CASE WHEN ' . $cond . ' THEN CAST(:today: AS date) - i.due_date ELSE 0 END AS days_overdue
             FROM invoice_status_v i JOIN clients c ON c.id = i.client_id JOIN invoices f ON f.id = i.id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY ' . $order . $limitSql,
            $binds,
        )->getResultArray();

        $truncated = $limit !== null && count($rows) > $limit;

        return [
            'rows' => array_map(static fn (array $r): array => [
                'id'           => (int) $r['id'],
                'currency'     => trim((string) $r['currency']),
                'overdue'      => (int) $r['overdue'] === 1,
                'days_overdue' => (int) $r['days_overdue'],
            ] + $r, $limit === null ? $rows : array_slice($rows, 0, $limit)),
            'truncated' => $truncated,
        ];
    }

    private static function escapeLike(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(int $id): ?array
    {
        $invoice = $this->db->query(
            'SELECT i.id, i.origin, i.type, i.number, i.external_ref, i.client_id, i.billing_month, i.issue_date, i.delivery_date, i.due_date,
                    i.currency, i.total, i.line_count, i.doc_language, i.vat_regime, i.exemption_code, i.payment_method,
                    i.template_version, i.snapshot_schema, i.snapshot, i.created_at, ' . ClientNameSql::snapshotName('i') . ' AS client_name,
                    i.total_entity_ccy, i.fx_rate, i.fx_source, i.fx_date, i.fx_table_no, i.fx_date_basis,
                    i.corrected_invoice_id, i.correction_reason, i.correction_type
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

        $st = $this->db->query('SELECT status, sent_at, send_count, last_reminder_at, paid_amount, credit_note_id, credit_note_number, credit_note_issue_date, corrected_number, corrected_issue_date FROM invoice_status_v WHERE id = :id:', ['id' => $id])->getRowArray() ?? [];

        $invoice['status']           = (string) ($st['status'] ?? 'issued');
        $invoice['sent_at']          = $st['sent_at'] ?? null;
        $invoice['send_count']       = (int) ($st['send_count'] ?? 0);
        $invoice['last_reminder_at'] = $st['last_reminder_at'] ?? null;

        foreach (['paid_amount', 'credit_note_id', 'credit_note_number', 'credit_note_issue_date', 'corrected_number', 'corrected_issue_date'] as $col) {
            $invoice[$col] = $st[$col] ?? null;
        }

        $invoice['history'] = $invoice['origin'] === 'external' ? [] : (new InvoiceMailer($this->db))->history($id);

        $store                      = new DocumentStore($this->db);
        $invoice['document']        = $store->meta($id);
        $invoice['report_document'] = $invoice['type'] === 'credit_note' ? null : $store->meta($id, 'report');

        $invoice['document_versions']      = [];
        $invoice['can_regenerate_report']  = false;
        $invoice['can_regenerate_invoice'] = false;
        $invoice['regen_invoice_deadline'] = null;
        $invoice['report_is_current']      = false;
        $invoice['invoice_is_current']     = false;

        if ($invoice['origin'] === 'issued') {
            $invoice = (new InvoiceRegenWindows($this->db))->attach($invoice);
            $now     = service('clock')->now();

            $invoice['document_versions']      = $store->versions($id);
            $invoice['can_regenerate_report']  = RegeneratePolicy::canRegenerate($invoice, 'report', $now) && $invoice['report_document'] !== null;
            $invoice['can_regenerate_invoice'] = RegeneratePolicy::canRegenerate($invoice, 'invoice', $now) && $invoice['document'] !== null;

            if ($invoice['can_regenerate_invoice']) {
                $invoice['regen_invoice_deadline'] = RegeneratePolicy::deadline($invoice)?->format(DATE_ATOM);
            }

            $type                          = (string) $invoice['type'];
            $invoice['report_is_current']  = $invoice['report_document'] !== null && $invoice['report_document']['template_version'] === DocumentTemplates::current('report', $type);
            $invoice['invoice_is_current'] = $invoice['document'] !== null && $invoice['document']['template_version'] === DocumentTemplates::current('invoice', $type);
        }

        return $invoice;
    }
}
