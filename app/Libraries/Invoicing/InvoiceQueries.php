<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

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
    public function list(int $limit = 100): array
    {
        $rows = $this->db->query(
            'SELECT i.id, i.origin, i.number, i.external_ref, i.issue_date, i.billing_month, i.currency, i.total, i.created_at, c.name AS client_name
             FROM invoice_status_v i JOIN clients c ON c.id = i.client_id
             ORDER BY i.issue_date DESC NULLS LAST, i.number DESC NULLS LAST, i.id DESC
             LIMIT :n:',
            ['n' => $limit + 1],
        )->getResultArray();

        $truncated = count($rows) > $limit;

        return [
            'rows'      => array_map(static fn (array $r): array => ['currency' => trim((string) $r['currency'])] + $r, array_slice($rows, 0, $limit)),
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
                    i.template_version, i.snapshot, i.created_at, c.name AS client_name
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

        $invoice['document'] = (new DocumentStore($this->db))->meta($id);

        return $invoice;
    }
}
