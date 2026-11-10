<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Quick 261110-kfy (WR-04, D-15): na stornovanu fakturu (existuje dobropis s corrected_invoice_id) sa neda vlozit
 * ziadna uhrada, ani priamym INSERT-om. Trigger ide cez ciastocny unikatny index invoices_one_credit_note_uq.
 */
final class AddPaymentsCancelledGuard extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "CREATE FUNCTION payment_cancelled_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM invoices WHERE corrected_invoice_id = NEW.invoice_id AND type = 'credit_note') THEN
                    RAISE EXCEPTION 'payment target % is cancelled by credit note', NEW.invoice_id
                        USING ERRCODE = 'integrity_constraint_violation', CONSTRAINT = 'payments_not_cancelled';
                END IF;

                RETURN NEW;
            END
            \$\$",
        );
        $this->db->query('CREATE TRIGGER payments_not_cancelled BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION payment_cancelled_guard()');
        $this->db->query("COMMENT ON FUNCTION payment_cancelled_guard() IS 'Rejects a payment for an invoice that has a credit note (D-15, WR-04)'");
    }

    public function down(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS payments_not_cancelled ON payments');
        $this->db->query('DROP FUNCTION IF EXISTS payment_cancelled_guard()');
    }
}
