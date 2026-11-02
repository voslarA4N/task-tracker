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

        $this->db->query(<<<'SQL'
            CREATE OR REPLACE VIEW invoice_status_v AS
            SELECT i.id, i.origin, i.type, i.number, i.external_ref, i.client_id, i.entity_id, i.billing_month, i.issue_date, i.due_date,
                   i.currency, i.total, i.counts_toward_limit, i.line_count, i.created_at,
                   CASE WHEN i.origin = 'external' THEN 'external'
                        WHEN i.type = 'invoice' AND cn.id IS NOT NULL THEN 'cancelled'
                        WHEN i.total > 0 AND COALESCE(p.paid, 0) >= i.total THEN 'paid'
                        WHEN COALESCE(p.paid, 0) > 0 THEN 'partially_paid'
                        WHEN s.sent_at IS NOT NULL THEN 'sent'
                        ELSE 'issued' END AS status,
                   s.sent_at AS sent_at,
                   COALESCE(s.send_count, 0)::integer AS send_count,
                   s.last_reminder_at AS last_reminder_at,
                   COALESCE(p.paid, 0)::numeric(12,2) AS paid_amount,
                   (CASE WHEN cn.id IS NOT NULL OR i.type = 'credit_note' THEN 0 ELSE i.total - COALESCE(p.paid, 0) END)::numeric(12,2) AS balance,
                   p.last_paid_on AS last_paid_on,
                   cn.id AS credit_note_id,
                   cn.number AS credit_note_number,
                   cn.issue_date AS credit_note_issue_date,
                   i.corrected_invoice_id AS corrected_invoice_id,
                   o.number AS corrected_number,
                   o.issue_date AS corrected_issue_date
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
            LEFT JOIN invoices cn ON cn.corrected_invoice_id = i.id AND cn.type = 'credit_note'
            LEFT JOIN invoices o ON o.id = i.corrected_invoice_id
            SQL);
        $this->db->query('GRANT SELECT ON invoice_status_v TO app_rw');

        $this->db->query('ALTER TABLE email_templates DROP CONSTRAINT email_templates_key_check');
        $this->db->query("ALTER TABLE email_templates ADD CONSTRAINT email_templates_key_check CHECK (key IN ('invoice','reminder','credit_note'))");

        foreach ($this->seed() as [$key, $language, $subject, $body]) {
            $this->db->query(
                'INSERT INTO email_templates (key, language, subject, body, revision, updated_at) VALUES (:key:, :language:, :subject:, :body:, 1, now())',
                ['key' => $key, 'language' => $language, 'subject' => $subject, 'body' => $body],
            );
        }
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM email_templates WHERE key = 'credit_note'");
        $this->db->query('ALTER TABLE email_templates DROP CONSTRAINT email_templates_key_check');
        $this->db->query("ALTER TABLE email_templates ADD CONSTRAINT email_templates_key_check CHECK (key IN ('invoice','reminder'))");

        $this->db->query('DROP VIEW IF EXISTS invoice_status_v');
        $this->db->query(<<<'SQL'
            CREATE VIEW invoice_status_v AS
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

    /**
     * @return list<array{string, string, string, string}>
     */
    private function seed(): array
    {
        return [
            ['credit_note', 'sk', 'Dobropis {{number}} k faktúre {{original_number}}', "Dobrý deň,\n\nv prílohe posielame dobropis {{number}} zo dňa {{issue_date}} k faktúre {{original_number}} zo dňa {{original_date}}. Dobropis stornuje celú pôvodnú faktúru.\n\nS pozdravom\n{{entity_name}}"],
            ['credit_note', 'pl', 'Faktura korygująca {{number}} do faktury {{original_number}}', "Dzień dobry,\n\nw załączeniu przesyłamy fakturę korygującą {{number}} z dnia {{issue_date}} do faktury {{original_number}} z dnia {{original_date}}. Faktura korygująca anuluje całą pierwotną fakturę.\n\nZ poważaniem\n{{entity_name}}"],
            ['credit_note', 'cs', 'Opravný daňový doklad {{number}} k faktuře {{original_number}}', "Dobrý den,\n\nv příloze zasíláme opravný daňový doklad {{number}} ze dne {{issue_date}} k faktuře {{original_number}} ze dne {{original_date}}. Doklad stornuje celou původní fakturu.\n\nS pozdravem\n{{entity_name}}"],
            ['credit_note', 'en', 'Credit note {{number}} for invoice {{original_number}}', "Hello,\n\nplease find attached credit note {{number}} dated {{issue_date}} for invoice {{original_number}} dated {{original_date}}. The credit note cancels the entire original invoice.\n\nKind regards\n{{entity_name}}"],
        ];
    }
}
