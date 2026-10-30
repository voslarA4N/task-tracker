<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * CUR-01, D-01: pevny CHECK (currency IN ('EUR','PLN','CZK')) v piatich tabulkach sa nahradi
 * cudzim klucom na currencies(code). ADD CONSTRAINT nemeni riadky (insert-only guard invoices ostava).
 */
final class ReplaceCurrencyChecks extends Migration
{
    private const TABLES = ['entities', 'clients', 'entity_bank_accounts', 'billing_allocations', 'invoices'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $rows = $this->db->query(
                "SELECT conname FROM pg_constraint WHERE conrelid = '{$table}'::regclass AND contype = 'c'
                 AND pg_get_constraintdef(oid) LIKE '%currency%' AND pg_get_constraintdef(oid) LIKE '%EUR%'",
            )->getResultArray();

            if ($rows === []) {
                throw new RuntimeException("No currency CHECK constraint found on {$table}.");
            }

            foreach ($rows as $row) {
                $this->db->query("ALTER TABLE {$table} DROP CONSTRAINT \"" . str_replace('"', '""', (string) $row['conname']) . '"');
            }

            $this->db->query("ALTER TABLE {$table} ADD CONSTRAINT {$table}_currency_fk FOREIGN KEY (currency) REFERENCES currencies(code)");
        }
    }

    public function down(): void
    {
        $extra = (int) $this->db->query("SELECT count(*) AS n FROM currencies WHERE code NOT IN ('EUR','PLN','CZK')")->getRow()->n;

        if ($extra > 0) {
            throw new RuntimeException($extra . ' extra currency row(s) exist; remove their usage and rows before rolling back ReplaceCurrencyChecks.');
        }

        foreach (self::TABLES as $table) {
            $this->db->query("ALTER TABLE {$table} DROP CONSTRAINT {$table}_currency_fk");
            $this->db->query("ALTER TABLE {$table} ADD CHECK (currency IN ('EUR','PLN','CZK'))");
        }
    }
}
