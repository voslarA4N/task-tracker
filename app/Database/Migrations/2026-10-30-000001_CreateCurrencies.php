<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CUR-01, D-01: zoznam podporovanych mien je dato. Nova mena = novy riadok (migracia pod ownerom).
 * app_rw cita, nikdy nemeni.
 */
final class CreateCurrencies extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "CREATE TABLE currencies (
                code char(3) PRIMARY KEY CHECK (code ~ '^[A-Z]{3}$'),
                decimals smallint NOT NULL DEFAULT 2 CHECK (decimals BETWEEN 0 AND 4),
                active boolean NOT NULL DEFAULT true,
                sort_order smallint NOT NULL
            )",
        );
        $this->db->query("INSERT INTO currencies (code, decimals, active, sort_order) VALUES ('EUR', 2, true, 1), ('PLN', 2, true, 2), ('CZK', 2, true, 3)");
        $this->db->query("COMMENT ON TABLE currencies IS 'Supported currencies (CUR-01, D-01); new currency = new row via migration; app_rw read-only'");
        $this->db->query('REVOKE INSERT, UPDATE, DELETE ON currencies FROM app_rw');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS currencies');
    }
}
