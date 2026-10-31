<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Dobropisy (Phase 9): databazove zaruky storna faktury jednym dobropisom.
 *
 * D-01, D-09, D-10: CHECK-y platneho dobropisu (origin issued, dovod, typ opravy, total <= 0).
 * D-02, D-14: najviac jeden dobropis na faktúru (ciastocny unikatny index) a cielovy trigger (vlastna vystavena faktura toho isteho subjektu, klienta a meny).
 * D-12: alloc_guard povoli prechod invoice_id X -> NULL iba pri existujucom dobropise a iba cez SECURITY DEFINER release_allocations().
 *       NIKDY GRANT UPDATE (invoice_id) pre app_rw.
 * D-18: dobropis nepotrebuje dokument 'report', PDF 'invoice' a riadky ano.
 * D-15: invoice_status_v ma stav 'cancelled' a 6 novych stlpcov na konci.
 * D-19: e-mailova sablona 'credit_note' v 4 jazykoch.
 */
final class AddCreditNotes extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE UNIQUE INDEX invoices_one_credit_note_uq ON invoices (corrected_invoice_id) WHERE type = 'credit_note'");

        $this->db->query(<<<'SQL'
            ALTER TABLE invoices ADD CONSTRAINT invoices_credit_note_fields_check CHECK (
                type <> 'credit_note' OR (
                    origin = 'issued'
                    AND correction_reason IS NOT NULL
                    AND char_length(btrim(correction_reason)) BETWEEN 1 AND 500
                    AND correction_type IS NOT NULL
                )
            )
            SQL);
        $this->db->query("ALTER TABLE invoices ADD CONSTRAINT invoices_credit_note_total_check CHECK (type <> 'credit_note' OR total <= 0)");

        $this->db->query(<<<'SQL'
            CREATE FUNCTION credit_note_target_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.type = 'credit_note' AND NEW.corrected_invoice_id IS NOT NULL THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM invoices o
                        WHERE o.id = NEW.corrected_invoice_id
                          AND o.type = 'invoice' AND o.origin = 'issued'
                          AND o.entity_id = NEW.entity_id
                          AND o.client_id = NEW.client_id
                          AND o.currency = NEW.currency
                    ) THEN
                        RAISE EXCEPTION 'credit note target % is not creditable', NEW.corrected_invoice_id USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        $this->db->query('CREATE TRIGGER invoices_credit_note_target BEFORE INSERT ON invoices FOR EACH ROW EXECUTE FUNCTION credit_note_target_guard()');

        $this->db->query(<<<'SQL'
            CREATE OR REPLACE FUNCTION alloc_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.invoice_id IS NOT NULL THEN
                    IF TG_OP = 'UPDATE'
                       AND NEW.invoice_id IS NULL
                       AND NEW.task_id IS NOT DISTINCT FROM OLD.task_id
                       AND NEW.hours IS NOT DISTINCT FROM OLD.hours
                       AND NEW.billing_month IS NOT DISTINCT FROM OLD.billing_month
                       AND NEW.rate IS NOT DISTINCT FROM OLD.rate
                       AND NEW.amount IS NOT DISTINCT FROM OLD.amount
                       AND NEW.currency IS NOT DISTINCT FROM OLD.currency
                       AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at
                       AND NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
                       AND EXISTS (SELECT 1 FROM invoices cn WHERE cn.type = 'credit_note' AND cn.corrected_invoice_id = OLD.invoice_id) THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'allocation % is locked', OLD.id USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                IF NEW.invoice_id IS NOT NULL THEN
                    IF NEW.task_id IS DISTINCT FROM OLD.task_id
                       OR NEW.hours IS DISTINCT FROM OLD.hours
                       OR NEW.billing_month IS DISTINCT FROM OLD.billing_month
                       OR NEW.rate IS DISTINCT FROM OLD.rate
                       OR NEW.amount IS DISTINCT FROM OLD.amount
                       OR NEW.currency IS DISTINCT FROM OLD.currency THEN
                        RAISE EXCEPTION 'allocation % lock must not change other columns', OLD.id USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);

        $this->db->query(<<<'SQL'
            CREATE FUNCTION release_allocations(p_credit_note_id bigint) RETURNS integer
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
            DECLARE
                v_count integer;
            BEGIN
                UPDATE billing_allocations a SET invoice_id = NULL
                FROM invoices cn
                WHERE cn.id = p_credit_note_id
                  AND cn.type = 'credit_note'
                  AND a.invoice_id = cn.corrected_invoice_id;
                GET DIAGNOSTICS v_count = ROW_COUNT;

                RETURN v_count;
            END;
            $$
            SQL);
        $this->db->query('REVOKE ALL ON FUNCTION release_allocations(bigint) FROM PUBLIC');
        $this->db->query('GRANT EXECUTE ON FUNCTION release_allocations(bigint) TO app_rw');

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
                       OR (v_inv.type = 'invoice' AND v_inv.snapshot_schema >= 2 AND NOT EXISTS (SELECT 1 FROM invoice_documents WHERE invoice_id = v_id AND kind = 'report')) THEN
                        RAISE EXCEPTION 'invoice % is incomplete', v_id USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;
                RETURN NULL;
            END;
            $$
            SQL);
        $this->db->query("COMMENT ON FUNCTION invoice_complete_check() IS 'Deferred completeness check: lines, totals, invoice PDF and (type invoice, snapshot_schema >= 2) report PDF; credit notes need no report'");
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

        $this->db->query('DROP FUNCTION release_allocations(bigint)');

        $this->db->query(<<<'SQL'
            CREATE OR REPLACE FUNCTION alloc_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.invoice_id IS NOT NULL THEN
                    RAISE EXCEPTION 'allocation % is locked', OLD.id USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                IF NEW.invoice_id IS NOT NULL THEN
                    IF NEW.task_id IS DISTINCT FROM OLD.task_id
                       OR NEW.hours IS DISTINCT FROM OLD.hours
                       OR NEW.billing_month IS DISTINCT FROM OLD.billing_month
                       OR NEW.rate IS DISTINCT FROM OLD.rate
                       OR NEW.amount IS DISTINCT FROM OLD.amount
                       OR NEW.currency IS DISTINCT FROM OLD.currency THEN
                        RAISE EXCEPTION 'allocation % lock must not change other columns', OLD.id USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);

        $this->db->query('DROP TRIGGER invoices_credit_note_target ON invoices');
        $this->db->query('DROP FUNCTION credit_note_target_guard()');
        $this->db->query('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_credit_note_total_check');
        $this->db->query('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_credit_note_fields_check');
        $this->db->query('DROP INDEX invoices_one_credit_note_uq');
    }
}
