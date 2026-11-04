<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Doklady balika pre uctovnicku (D-09, D-10). Rovnaka podmienka vyberu ako ReportCalculator (subjekt, vystavene,
 * datum dodania v obdobi), aby sucet sedel s prehladom. Nikdy nenacitava obsah PDF, iba jeho velkost.
 */
final class BundleSelector
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
     * @return list<array{id: int, origin: string, type: string, number: ?string, external_ref: ?string, client_name: string, issue_date: ?string, turnover_date: ?string, due_date: ?string, currency: string, total: string, fx_rate: ?string, total_entity_ccy: ?string, status: string, last_paid_on: ?string, doc_language: string, byte_size: ?int}>
     */
    public function documents(int $entityId, ReportPeriod $p): array
    {
        $rows = $this->db->query(
            "SELECT f.id, f.origin, f.type, f.number, f.external_ref, c.name AS client_name, f.issue_date, f.turnover_date, f.due_date,
                    f.currency, f.total, f.fx_rate, f.total_entity_ccy, i.status, i.last_paid_on, f.doc_language, d.byte_size
             FROM invoices f
             JOIN invoice_status_v i ON i.id = f.id
             JOIN clients c ON c.id = f.client_id
             LEFT JOIN LATERAL (SELECT x.byte_size FROM invoice_documents x WHERE x.invoice_id = f.id AND x.kind = 'invoice' ORDER BY x.version DESC LIMIT 1) d ON true
             WHERE f.entity_id = :e: AND f.origin = 'issued'
               AND f.turnover_date >= :from: AND f.turnover_date < :until:
             ORDER BY f.turnover_date, f.number, f.id",
            ['e' => $entityId, 'from' => $p->from, 'until' => $p->until],
        )->getResultArray();

        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'id'               => (int) $r['id'],
                'origin'           => (string) $r['origin'],
                'type'             => (string) $r['type'],
                'number'           => $r['number'] === null ? null : (string) $r['number'],
                'external_ref'     => $r['external_ref'] === null ? null : (string) $r['external_ref'],
                'client_name'      => (string) $r['client_name'],
                'issue_date'       => $r['issue_date'] === null ? null : (string) $r['issue_date'],
                'turnover_date'    => $r['turnover_date'] === null ? null : (string) $r['turnover_date'],
                'due_date'         => $r['due_date'] === null ? null : (string) $r['due_date'],
                'currency'         => trim((string) $r['currency']),
                'total'            => (string) $r['total'],
                'fx_rate'          => $r['fx_rate'] === null ? null : (string) $r['fx_rate'],
                'total_entity_ccy' => $r['total_entity_ccy'] === null ? null : (string) $r['total_entity_ccy'],
                'status'           => (string) $r['status'],
                'last_paid_on'     => $r['last_paid_on'] === null ? null : (string) $r['last_paid_on'],
                'doc_language'     => trim((string) ($r['doc_language'] ?? 'sk')),
                'byte_size'        => $r['byte_size'] === null ? null : (int) $r['byte_size'],
            ];
        }

        return $out;
    }
}
