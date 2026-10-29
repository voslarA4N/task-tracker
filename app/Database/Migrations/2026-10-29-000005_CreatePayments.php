<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Uhrady faktur (Phase 7, D-18, D-28). Insert-only: grant len SELECT, INSERT pre app_rw, immutable_guard
 * (aj pre ownera) a truncate_guard. Storno omylom zadanej uhrady je samostatny zaznam v payment_voids.
 */
final class CreatePayments extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE payments (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                invoice_id bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
                paid_on date NOT NULL,
                amount numeric(12,2) NOT NULL CHECK (amount > 0),
                note text NULL CHECK (note IS NULL OR char_length(note) <= 200),
                request_key uuid NULL UNIQUE,
                created_at timestamptz NOT NULL,
                created_by integer NULL REFERENCES users(id)
            )
            SQL);
        $this->db->query('CREATE INDEX payments_invoice_idx ON payments (invoice_id)');

        $this->db->query(<<<'SQL'
            CREATE TABLE payment_voids (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                payment_id bigint NOT NULL UNIQUE REFERENCES payments(id) ON DELETE RESTRICT,
                reason text NOT NULL CHECK (char_length(btrim(reason)) BETWEEN 1 AND 200),
                voided_at timestamptz NOT NULL,
                voided_by integer NULL REFERENCES users(id)
            )
            SQL);

        foreach (['payments', 'payment_voids'] as $t) {
            $this->db->query("CREATE TRIGGER {$t}_immutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION immutable_guard()");
            $this->db->query("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION truncate_guard()");
            $this->db->query("GRANT SELECT, INSERT ON TABLE {$t} TO app_rw");
        }

        $this->db->query("COMMENT ON TABLE payments IS 'Invoice payments (D-18); insert-only, balance derived in invoice_status_v'");
        $this->db->query("COMMENT ON TABLE payment_voids IS 'Void of a mistaken payment (D-28); insert-only, reason required'");
    }

    public function down(): void
    {
        foreach (['payment_voids', 'payments'] as $t) {
            $this->db->query("DROP TRIGGER IF EXISTS {$t}_no_truncate ON {$t}");
            $this->db->query("DROP TRIGGER IF EXISTS {$t}_immutable ON {$t}");
        }
        $this->db->query('DROP TABLE IF EXISTS payment_voids');
        $this->db->query('DROP TABLE IF EXISTS payments');
    }
}
