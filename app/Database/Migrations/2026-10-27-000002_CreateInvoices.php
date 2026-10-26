<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

/**
 * Faktury (Phase 5, D-16..D-22). Nemennost vystaveneho dokladu strazi databaza:
 * insert-only grant pre app_rw, immutable_guard (aj pre ownera), CHECK sha256 PDF a deferred
 * kontrola uplnosti (riadky, sucet, kod DPH, PDF) pri commite.
 */
final class CreateInvoices extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE invoice_counters (
                series_id bigint NOT NULL REFERENCES invoice_series(id),
                period_key text NOT NULL CHECK (period_key ~ '^\d{4}(\d{2})?$'),
                last_value integer NOT NULL CHECK (last_value >= 1),
                PRIMARY KEY (series_id, period_key)
            )
            SQL);

        $this->db->query(<<<'SQL'
            CREATE TABLE invoices (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                origin text NOT NULL CHECK (origin IN ('issued','external')),
                type text NOT NULL DEFAULT 'invoice' CHECK (type IN ('invoice','credit_note')),
                request_key uuid NULL UNIQUE,
                external_ref text NULL CHECK (char_length(external_ref) BETWEEN 1 AND 300),
                entity_id bigint NULL REFERENCES entities(id),
                entity_version_id bigint NULL REFERENCES entity_versions(id),
                series_id bigint NULL REFERENCES invoice_series(id),
                number text NULL CHECK (char_length(number) BETWEEN 1 AND 30),
                client_id bigint NOT NULL REFERENCES clients(id),
                billing_month char(7) NOT NULL CHECK (billing_month ~ '^\d{4}-(0[1-9]|1[0-2])$'),
                corrected_invoice_id bigint NULL REFERENCES invoices(id),
                correction_reason text NULL CHECK (char_length(correction_reason) <= 500),
                correction_type text NULL CHECK (correction_type ~ '^[a-z_]{3,30}$'),
                issue_date date NULL,
                delivery_date date NULL,
                due_date date NULL,
                turnover_date date NULL,
                currency char(3) NOT NULL CHECK (currency IN ('EUR','PLN','CZK')),
                total numeric(12,2) NOT NULL,
                total_entity_ccy numeric(12,2) NULL,
                counts_toward_limit boolean NOT NULL,
                line_count smallint NOT NULL CHECK (line_count >= 1),
                fx_rate numeric(18,8) NULL CHECK (fx_rate > 0),
                fx_source text NULL CHECK (fx_source IN ('NBP','ECB','CNB','manual')),
                fx_date date NULL,
                fx_table_no text NULL,
                fx_date_basis text NULL CHECK (fx_date_basis ~ '^[a-z_]{3,40}$'),
                doc_language text NULL CHECK (doc_language IN ('sk','pl','cs','en')),
                vat_regime text NULL,
                billing_mode text NULL CHECK (billing_mode IN ('domestic','eu_b2b','non_eu')),
                exemption_code text NULL,
                entity_vat_id text NULL,
                client_identifier_type text NULL,
                client_identifier_value text NULL,
                client_vat_id text NULL,
                bank_iban text NULL,
                bank_swift text NULL,
                payment_method text NOT NULL DEFAULT 'transfer' CHECK (payment_method IN ('transfer','cash','card','other')),
                payment_note text NULL CHECK (char_length(payment_note) <= 500),
                snapshot jsonb NOT NULL CHECK (jsonb_typeof(snapshot) = 'object'),
                snapshot_schema smallint NOT NULL CHECK (snapshot_schema >= 1),
                template_version text NULL,
                created_at timestamptz NOT NULL,
                created_by integer NULL REFERENCES users(id),
                UNIQUE (series_id, number),
                UNIQUE (entity_id, number),
                CHECK ((type = 'credit_note') = (corrected_invoice_id IS NOT NULL)),
                CHECK (type = 'credit_note' OR total >= 0),
                CONSTRAINT invoices_origin_shape CHECK (
                    (origin = 'issued'
                        AND entity_id IS NOT NULL AND entity_version_id IS NOT NULL
                        AND series_id IS NOT NULL AND number IS NOT NULL
                        AND issue_date IS NOT NULL AND delivery_date IS NOT NULL
                        AND due_date IS NOT NULL AND turnover_date IS NOT NULL
                        AND doc_language IS NOT NULL AND vat_regime IS NOT NULL
                        AND billing_mode IS NOT NULL AND template_version IS NOT NULL
                        AND total_entity_ccy IS NOT NULL AND external_ref IS NULL)
                    OR
                    (origin = 'external'
                        AND series_id IS NULL AND number IS NULL AND entity_id IS NULL
                        AND entity_version_id IS NULL AND turnover_date IS NULL
                        AND request_key IS NULL AND template_version IS NULL
                        AND counts_toward_limit = false AND type = 'invoice')
                )
            )
            SQL);
        $this->db->query("CREATE UNIQUE INDEX invoices_external_uq ON invoices (client_id, billing_month, coalesce(external_ref, '')) WHERE origin = 'external'");
        $this->db->query('CREATE INDEX invoices_client_month_idx ON invoices (client_id, billing_month)');
        $this->db->query('CREATE INDEX invoices_entity_issue_idx ON invoices (entity_id, issue_date)');
        $this->db->query('CREATE INDEX invoices_series_issue_idx ON invoices (series_id, issue_date)');

        $this->db->query(<<<'SQL'
            CREATE TABLE invoice_lines (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                invoice_id bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
                position smallint NOT NULL CHECK (position >= 1),
                source_allocation_id bigint NULL,
                source_task_id bigint NULL,
                title text NOT NULL CHECK (char_length(title) >= 1),
                task_ref text NULL,
                portal_url text NULL CHECK (portal_url ~ '^https?://'),
                estimated_hours numeric(7,2) NULL,
                hours numeric(7,2) NOT NULL CHECK (hours <> 0),
                rate numeric(12,2) NOT NULL CHECK (rate >= 0),
                amount numeric(12,2) NOT NULL,
                vat_code text NULL,
                CHECK (amount = round(hours * rate, 2)),
                UNIQUE (invoice_id, position)
            )
            SQL);
        $this->db->query('CREATE INDEX invoice_lines_alloc_idx ON invoice_lines (source_allocation_id)');

        $this->db->query(<<<'SQL'
            CREATE TABLE invoice_documents (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                invoice_id bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
                kind text NOT NULL CHECK (kind IN ('invoice','report')),
                content bytea NOT NULL,
                byte_size integer NOT NULL CHECK (byte_size = octet_length(content)),
                sha256 char(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$' AND sha256 = encode(sha256(content), 'hex')),
                template_version text NOT NULL,
                renderer text NOT NULL,
                created_at timestamptz NOT NULL,
                UNIQUE (invoice_id, kind)
            )
            SQL);

        $this->db->query(<<<'SQL'
            CREATE FUNCTION immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is immutable', TG_TABLE_NAME USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$
            SQL);
        $this->db->query(<<<'SQL'
            CREATE FUNCTION counter_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'invoice counter may not be deleted' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF NEW.series_id = OLD.series_id AND NEW.period_key = OLD.period_key AND NEW.last_value = OLD.last_value + 1 THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'invoice counter may only increase by one' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$
            SQL);
        $this->db->query(<<<'SQL'
            CREATE FUNCTION invoice_complete_check() RETURNS trigger LANGUAGE plpgsql AS $$
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

        foreach (['invoices', 'invoice_lines', 'invoice_documents'] as $t) {
            $this->db->query("CREATE TRIGGER {$t}_immutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION immutable_guard()");
        }
        $this->db->query('CREATE TRIGGER invoice_counters_guard BEFORE UPDATE OR DELETE ON invoice_counters FOR EACH ROW EXECUTE FUNCTION counter_guard()');
        $this->db->query('CREATE CONSTRAINT TRIGGER invoices_complete AFTER INSERT ON invoices DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION invoice_complete_check()');
        $this->db->query('CREATE CONSTRAINT TRIGGER invoice_lines_complete AFTER INSERT ON invoice_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION invoice_complete_check()');

        $this->db->query("COMMENT ON TABLE invoices IS 'Issued and external invoices (D-18..D-22); insert-only for app_rw, immutable_guard blocks UPDATE/DELETE even for owner; state is derived in invoice_status_v'");
        $this->db->query("COMMENT ON TABLE invoice_lines IS 'Invoice lines (D-07); immutable, completeness checked at commit'");
        $this->db->query("COMMENT ON TABLE invoice_documents IS 'Stored PDF documents (D-16); sha256 verified by CHECK, immutable'");
        $this->db->query("COMMENT ON TABLE invoice_counters IS 'Gapless counters per series and period; only +1 updates allowed (counter_guard)'");

        // Faktúry sú insert-only (D-20): zámerne bez grantMutable(), grantDelete() aj grantUpdateColumns().
        // Iba čítač: stĺpec last_value (counter_guard povolí len +1).
        $this->grantUpdateColumns('invoice_counters', ['last_value']);
    }

    public function down(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS invoice_lines_complete ON invoice_lines');
        $this->db->query('DROP TRIGGER IF EXISTS invoices_complete ON invoices');
        $this->db->query('DROP TRIGGER IF EXISTS invoice_counters_guard ON invoice_counters');

        foreach (['invoice_documents', 'invoice_lines', 'invoices'] as $t) {
            $this->db->query("DROP TRIGGER IF EXISTS {$t}_immutable ON {$t}");
        }
        $this->db->query('DROP FUNCTION IF EXISTS invoice_complete_check()');
        $this->db->query('DROP FUNCTION IF EXISTS counter_guard()');
        $this->db->query('DROP FUNCTION IF EXISTS immutable_guard()');
        $this->db->query('DROP TABLE IF EXISTS invoice_documents');
        $this->db->query('DROP TABLE IF EXISTS invoice_lines');
        $this->db->query('DROP TABLE IF EXISTS invoices');
        $this->db->query('DROP TABLE IF EXISTS invoice_counters');
    }
}
