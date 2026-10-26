<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Odvodeny stav faktury (D-18). Bez snapshot (velky jsonb). Phase 7 a 9 smu stlpce len
 * pripajat na koniec cez CREATE OR REPLACE VIEW (Pitfall 6), nikdy nemenit poradie ani typy.
 */
final class CreateInvoiceStatusView extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE VIEW invoice_status_v AS
            SELECT id, origin, type, number, external_ref, client_id, entity_id, billing_month, issue_date, due_date,
                   currency, total, counts_toward_limit, line_count, created_at,
                   CASE WHEN origin = 'external' THEN 'external' ELSE 'issued' END AS status
            FROM invoices
            SQL);
        $this->db->query('GRANT SELECT ON invoice_status_v TO app_rw');
    }

    public function down(): void
    {
        $this->db->query('DROP VIEW IF EXISTS invoice_status_v');
    }
}
