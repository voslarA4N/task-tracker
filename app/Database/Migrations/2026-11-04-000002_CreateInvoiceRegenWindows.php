<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Faza 11.1 (WR-03): lehota pregenerovania PDF faktury sa zmrazi pri vystaveni. Samostatna insert-only tabulka,
 * aby sa nedotkla invoices ani jej triggerov nemennosti. Faktury bez riadku (vystavene pred touto migraciou)
 * maju lehotu created_at + 72 h, teda to, co platilo, ked boli vystavene.
 */
final class CreateInvoiceRegenWindows extends Migration
{
    public function up(): void
    {
        $this->db->query(
            'CREATE TABLE invoice_regen_windows (
                invoice_id bigint PRIMARY KEY REFERENCES invoices(id) ON DELETE RESTRICT,
                hours integer NOT NULL CHECK (hours BETWEEN 0 AND 8760),
                regen_until timestamptz NOT NULL,
                created_at timestamptz NOT NULL
            )',
        );
        $this->db->query('CREATE TRIGGER invoice_regen_windows_immutable BEFORE UPDATE OR DELETE ON invoice_regen_windows FOR EACH ROW EXECUTE FUNCTION immutable_guard()');
        $this->db->query('CREATE TRIGGER invoice_regen_windows_no_truncate BEFORE TRUNCATE ON invoice_regen_windows FOR EACH STATEMENT EXECUTE FUNCTION truncate_guard()');
        $this->db->query('GRANT SELECT, INSERT ON TABLE invoice_regen_windows TO app_rw');
        $this->db->query("COMMENT ON TABLE invoice_regen_windows IS 'Zmrazena lehota pregenerovania PDF faktury (WR-03), insert-only v transakcii vystavenia; bez riadku plati created_at + 72 h'");
    }

    public function down(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS invoice_regen_windows_no_truncate ON invoice_regen_windows');
        $this->db->query('DROP TRIGGER IF EXISTS invoice_regen_windows_immutable ON invoice_regen_windows');
        $this->db->query('DROP TABLE IF EXISTS invoice_regen_windows');
    }
}
