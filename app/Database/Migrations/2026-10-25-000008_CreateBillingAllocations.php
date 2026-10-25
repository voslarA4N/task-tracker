<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

/**
 * Poradie zamkov (Pitfall 10, Phase 5 ho musi dodrzat): najprv riadok tasks FOR UPDATE, potom cast.
 * Vynimku z triggra pre dobropis doplni Phase 9. invoice_id zatial bez FK (invoices vznikne vo Phase 5).
 */
final class CreateBillingAllocations extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE billing_allocations (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                task_id bigint NOT NULL REFERENCES tasks(id) ON DELETE RESTRICT,
                hours numeric(7,2) NOT NULL CHECK (hours > 0),
                billing_month char(7) NOT NULL CHECK (billing_month ~ '^\d{4}-(0[1-9]|1[0-2])$'),
                rate numeric(12,2) NOT NULL CHECK (rate >= 0),
                amount numeric(12,2) NOT NULL,
                currency char(3) NOT NULL CHECK (currency IN ('EUR','PLN','CZK')),
                invoice_id bigint NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CHECK (amount = round(hours * rate, 2))
            )
            SQL);
        $this->db->query('CREATE INDEX alloc_task_idx ON billing_allocations (task_id)');
        $this->db->query('CREATE INDEX alloc_unlocked_month_idx ON billing_allocations (billing_month, task_id) WHERE invoice_id IS NULL');
        $this->db->query(<<<'SQL'
            CREATE FUNCTION alloc_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.invoice_id IS NOT NULL THEN
                    RAISE EXCEPTION 'allocation % is locked', OLD.id USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        $this->db->query('CREATE TRIGGER billing_allocations_guard BEFORE UPDATE OR DELETE ON billing_allocations FOR EACH ROW EXECUTE FUNCTION alloc_guard()');
        $this->db->query("COMMENT ON TABLE billing_allocations IS 'Billing parts (D-07, ALC-01); locked parts (invoice_id set) are immutable via alloc_guard (D-09); lock order: tasks row FOR UPDATE first, then allocation; credit-note exception added in Phase 9'");
        // NIKDY invoice_id, task_id, currency.
        $this->grantUpdateColumns('billing_allocations', ['hours', 'billing_month', 'rate', 'amount', 'updated_at']);
        $this->grantDelete('billing_allocations');
    }

    public function down(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS billing_allocations_guard ON billing_allocations');
        $this->db->query('DROP FUNCTION IF EXISTS alloc_guard()');
        $this->db->query('DROP TABLE IF EXISTS billing_allocations');
    }
}
