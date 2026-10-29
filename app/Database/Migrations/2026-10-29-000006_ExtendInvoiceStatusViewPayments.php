<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * invoice_status_v v2 (Phase 7, D-19, D-21, D-22): 19 stlpcov v1 bez zmeny, na koniec paid_amount, balance, last_paid_on.
 * Priorita stavu: external, paid, partially_paid, sent, issued. Faktura s total 0 nie je nikdy automaticky uhradena.
 * Bez casu v definicii (po splatnosti sa pocita z Clock v aplikacii).
 */
final class ExtendInvoiceStatusViewPayments extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE OR REPLACE VIEW invoice_status_v AS
            SELECT i.id, i.origin, i.type, i.number, i.external_ref, i.client_id, i.entity_id, i.billing_month, i.issue_date, i.due_date,
                   i.currency, i.total, i.counts_toward_limit, i.line_count, i.created_at,
                   CASE WHEN i.origin = 'external' THEN 'external'
                        WHEN i.total > 0 AND COALESCE(p.paid, 0) >= i.total THEN 'paid'
                        WHEN COALESCE(p.paid, 0) > 0 THEN 'partially_paid'
                        WHEN s.sent_at IS NOT NULL THEN 'sent'
                        ELSE 'issued' END AS status,
                   s.sent_at AS sent_at,
                   COALESCE(s.send_count, 0)::integer AS send_count,
                   s.last_reminder_at AS last_reminder_at,
                   COALESCE(p.paid, 0)::numeric(12,2) AS paid_amount,
                   (i.total - COALESCE(p.paid, 0))::numeric(12,2) AS balance,
                   p.last_paid_on AS last_paid_on
            FROM invoices i
            LEFT JOIN (
                SELECT x.invoice_id,
                       MAX(x.sent_at) FILTER (WHERE x.counts_as_sent) AS sent_at,
                       COUNT(*) FILTER (WHERE x.kind IN ('invoice','resend')) AS send_count,
                       MAX(x.sent_at) FILTER (WHERE x.kind = 'reminder') AS last_reminder_at
                FROM invoice_sends x
                WHERE NOT EXISTS (SELECT 1 FROM invoice_send_voids v WHERE v.send_id = x.id)
                GROUP BY x.invoice_id
            ) s ON s.invoice_id = i.id
            LEFT JOIN (
                SELECT y.invoice_id, SUM(y.amount) AS paid, MAX(y.paid_on) AS last_paid_on
                FROM payments y
                WHERE NOT EXISTS (SELECT 1 FROM payment_voids pv WHERE pv.payment_id = y.id)
                GROUP BY y.invoice_id
            ) p ON p.invoice_id = i.id
            SQL);
        $this->db->query('GRANT SELECT ON invoice_status_v TO app_rw');
    }

    public function down(): void
    {
        $this->db->query('DROP VIEW IF EXISTS invoice_status_v');
        $this->db->query(<<<'SQL'
            CREATE VIEW invoice_status_v AS
            SELECT i.id, i.origin, i.type, i.number, i.external_ref, i.client_id, i.entity_id, i.billing_month, i.issue_date, i.due_date,
                   i.currency, i.total, i.counts_toward_limit, i.line_count, i.created_at,
                   CASE WHEN i.origin = 'external' THEN 'external' WHEN s.sent_at IS NOT NULL THEN 'sent' ELSE 'issued' END AS status,
                   s.sent_at AS sent_at,
                   COALESCE(s.send_count, 0)::integer AS send_count,
                   s.last_reminder_at AS last_reminder_at
            FROM invoices i
            LEFT JOIN (
                SELECT x.invoice_id,
                       MAX(x.sent_at) FILTER (WHERE x.counts_as_sent) AS sent_at,
                       COUNT(*) FILTER (WHERE x.kind IN ('invoice','resend')) AS send_count,
                       MAX(x.sent_at) FILTER (WHERE x.kind = 'reminder') AS last_reminder_at
                FROM invoice_sends x
                WHERE NOT EXISTS (SELECT 1 FROM invoice_send_voids v WHERE v.send_id = x.id)
                GROUP BY x.invoice_id
            ) s ON s.invoice_id = i.id
            SQL);
        $this->db->query('GRANT SELECT ON invoice_status_v TO app_rw');
    }
}
