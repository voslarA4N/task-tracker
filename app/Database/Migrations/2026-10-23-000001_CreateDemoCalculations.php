<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateDemoCalculations extends Migration
{
    public function up(): void
    {
        // Bez grantMutable(): tabulka je insert-only pre app_rw (default privileges).
        $this->db->query(<<<'SQL'
            CREATE TABLE demo_calculations (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                hours numeric(7,2) NOT NULL,
                rate numeric(12,2) NOT NULL,
                amount numeric(12,2) NOT NULL,
                created_at timestamptz NOT NULL,
                CONSTRAINT demo_calculations_hours_check CHECK (hours > 0),
                CONSTRAINT demo_calculations_rate_check CHECK (rate >= 0),
                CONSTRAINT demo_calculations_amount_check CHECK (amount = round(hours * rate, 2))
            )
            SQL);
        $this->db->query("COMMENT ON TABLE demo_calculations IS 'Walking skeleton (Phase 1): insert-only demo, app_rw has SELECT/INSERT only'");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS demo_calculations');
    }
}
