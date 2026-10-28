<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WR-06 (review Phase 5): citac bez medzier strazi databaza aj pri INSERT, nielen pri UPDATE.
 * Novy riadok obdobia smie zacat iba hodnotou 1, alebo initial_number radu, ak rad este nema
 * ziadnu fakturu (rovnake pravidlo ako NumberAllocator, D-12). Platí aj pre ownera.
 */
final class GuardInvoiceCounterInsert extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE OR REPLACE FUNCTION counter_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_first integer;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'invoice counter may not be deleted' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF EXISTS (SELECT 1 FROM invoices WHERE series_id = NEW.series_id) THEN
                        v_first := 1;
                    ELSE
                        SELECT initial_number INTO v_first FROM invoice_series WHERE id = NEW.series_id;
                    END IF;
                    IF NEW.last_value = v_first THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'invoice counter must start at %', coalesce(v_first, 1) USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF NEW.series_id = OLD.series_id AND NEW.period_key = OLD.period_key AND NEW.last_value = OLD.last_value + 1 THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'invoice counter may only increase by one' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$
            SQL);
        $this->db->query('DROP TRIGGER IF EXISTS invoice_counters_guard ON invoice_counters');
        $this->db->query('CREATE TRIGGER invoice_counters_guard BEFORE INSERT OR UPDATE OR DELETE ON invoice_counters FOR EACH ROW EXECUTE FUNCTION counter_guard()');
        $this->db->query("COMMENT ON TABLE invoice_counters IS 'Gapless counters per series and period; INSERT only at 1 (or initial_number for the first document of the series), only +1 updates (counter_guard)'");
    }

    public function down(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS invoice_counters_guard ON invoice_counters');
        $this->db->query(<<<'SQL'
            CREATE OR REPLACE FUNCTION counter_guard() RETURNS trigger LANGUAGE plpgsql AS $$
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
        $this->db->query('CREATE TRIGGER invoice_counters_guard BEFORE UPDATE OR DELETE ON invoice_counters FOR EACH ROW EXECUTE FUNCTION counter_guard()');
        $this->db->query("COMMENT ON TABLE invoice_counters IS 'Gapless counters per series and period; only +1 updates allowed (counter_guard)'");
    }
}
