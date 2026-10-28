<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * D-05 (Phase 6): vlastna faktura so snapshot_schema >= 2 musi mat v invoice_documents aj dokument kind = 'report'.
 * Faktury schema 1 a externe faktury vykaz nevyzaduju. Trigger sa nemeni, meni sa len telo funkcie.
 */
final class RequireReportDocument extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_complete_check() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_id bigint;
                v_inv invoices%ROWTYPE;
                v_count bigint;
                v_sum numeric;
            BEGIN
                IF TG_TABLE_NAME = 'invoices' THEN
                    v_id := NEW.id;
                ELSE
                    v_id := NEW.invoice_id;
                END IF;
                SELECT * INTO v_inv FROM invoices WHERE id = v_id;
                SELECT count(*), coalesce(sum(amount), 0) INTO v_count, v_sum FROM invoice_lines WHERE invoice_id = v_id;
                IF v_count <> v_inv.line_count OR v_sum <> v_inv.total THEN
                    RAISE EXCEPTION 'invoice % is incomplete', v_id USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF v_inv.origin = 'issued' THEN
                    IF EXISTS (SELECT 1 FROM invoice_lines WHERE invoice_id = v_id AND vat_code IS NULL)
                       OR NOT EXISTS (SELECT 1 FROM invoice_documents WHERE invoice_id = v_id AND kind = 'invoice')
                       OR (v_inv.snapshot_schema >= 2 AND NOT EXISTS (SELECT 1 FROM invoice_documents WHERE invoice_id = v_id AND kind = 'report')) THEN
                        RAISE EXCEPTION 'invoice % is incomplete', v_id USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;
                RETURN NULL;
            END;
            $$
            SQL);
        $this->db->query("COMMENT ON FUNCTION invoice_complete_check() IS 'Deferred completeness check: lines, totals, invoice PDF and (snapshot_schema >= 2) report PDF'");
    }

    public function down(): void
    {
        $this->db->query(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_complete_check() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_id bigint;
                v_inv invoices%ROWTYPE;
                v_count bigint;
                v_sum numeric;
            BEGIN
                IF TG_TABLE_NAME = 'invoices' THEN
                    v_id := NEW.id;
                ELSE
                    v_id := NEW.invoice_id;
                END IF;
                SELECT * INTO v_inv FROM invoices WHERE id = v_id;
                SELECT count(*), coalesce(sum(amount), 0) INTO v_count, v_sum FROM invoice_lines WHERE invoice_id = v_id;
                IF v_count <> v_inv.line_count OR v_sum <> v_inv.total THEN
                    RAISE EXCEPTION 'invoice % is incomplete', v_id USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF v_inv.origin = 'issued' THEN
                    IF EXISTS (SELECT 1 FROM invoice_lines WHERE invoice_id = v_id AND vat_code IS NULL)
                       OR NOT EXISTS (SELECT 1 FROM invoice_documents WHERE invoice_id = v_id AND kind = 'invoice') THEN
                        RAISE EXCEPTION 'invoice % is incomplete', v_id USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;
                RETURN NULL;
            END;
            $$
            SQL);
        $this->db->query('COMMENT ON FUNCTION invoice_complete_check() IS NULL');
    }
}
