<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * D-21, ALC-03: invoice_id ma FK na invoices, alloc_guard dovoli iba prechod NULL -> faktura
 * (bez zmeny ostatnych stlpcov) a zamok robi len SECURITY DEFINER funkcia lock_allocations().
 * NIKDY GRANT UPDATE (invoice_id) pre app_rw: zamok iba cez lock_allocations() (Phase 4 AllocationGuardTest).
 */
final class LockAllocationsToInvoices extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE billing_allocations ADD CONSTRAINT billing_allocations_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT');

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

        $this->db->query(<<<'SQL'
            CREATE FUNCTION lock_allocations(p_invoice_id bigint, p_ids bigint[]) RETURNS integer
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
            DECLARE
                v_count integer;
            BEGIN
                UPDATE billing_allocations a SET invoice_id = p_invoice_id
                FROM invoices i, tasks t
                WHERE i.id = p_invoice_id
                  AND a.id = ANY(p_ids)
                  AND a.invoice_id IS NULL
                  AND t.id = a.task_id
                  AND t.client_id = i.client_id
                  AND a.billing_month = i.billing_month
                  AND a.currency = i.currency
                  AND EXISTS (SELECT 1 FROM invoice_lines l WHERE l.invoice_id = p_invoice_id AND l.source_allocation_id = a.id);
                GET DIAGNOSTICS v_count = ROW_COUNT;

                RETURN v_count;
            END;
            $$
            SQL);
        $this->db->query('REVOKE ALL ON FUNCTION lock_allocations(bigint, bigint[]) FROM PUBLIC');
        $this->db->query('GRANT EXECUTE ON FUNCTION lock_allocations(bigint, bigint[]) TO app_rw');
    }

    public function down(): void
    {
        $this->db->query('DROP FUNCTION IF EXISTS lock_allocations(bigint, bigint[])');
        $this->db->query(<<<'SQL'
            CREATE OR REPLACE FUNCTION alloc_guard() RETURNS trigger LANGUAGE plpgsql AS $$
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
        $this->db->query('ALTER TABLE billing_allocations DROP CONSTRAINT IF EXISTS billing_allocations_invoice_fk');
    }
}
