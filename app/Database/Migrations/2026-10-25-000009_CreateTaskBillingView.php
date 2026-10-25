<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateTaskBillingView extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE VIEW task_billing_v AS
            SELECT
                s.task_id, s.client_id, s.status, s.locked_count, s.unlocked_count,
                s.billed_hours, s.planned_hours, s.billed_hours + s.planned_hours AS allocated_hours,
                s.billed_amount, s.planned_amount,
                CASE
                    WHEN s.locked_count = 0 THEN 'unbilled'
                    WHEN s.status = 'done' AND s.unlocked_count = 0 THEN 'billed'
                    ELSE 'partial'
                END AS billing_state
            FROM (
                SELECT
                    t.id AS task_id, t.client_id, t.status,
                    COUNT(a.id) FILTER (WHERE a.invoice_id IS NOT NULL) AS locked_count,
                    COUNT(a.id) FILTER (WHERE a.invoice_id IS NULL) AS unlocked_count,
                    COALESCE(SUM(a.hours) FILTER (WHERE a.invoice_id IS NOT NULL), 0)::numeric(9,2) AS billed_hours,
                    COALESCE(SUM(a.hours) FILTER (WHERE a.invoice_id IS NULL), 0)::numeric(9,2) AS planned_hours,
                    COALESCE(SUM(a.amount) FILTER (WHERE a.invoice_id IS NOT NULL), 0)::numeric(14,2) AS billed_amount,
                    COALESCE(SUM(a.amount) FILTER (WHERE a.invoice_id IS NULL), 0)::numeric(14,2) AS planned_amount
                FROM tasks t
                LEFT JOIN billing_allocations a ON a.task_id = t.id
                GROUP BY t.id, t.client_id, t.status
            ) s
            SQL);
        $this->db->query('GRANT SELECT ON task_billing_v TO app_rw');
    }

    public function down(): void
    {
        $this->db->query('DROP VIEW IF EXISTS task_billing_v');
    }
}
