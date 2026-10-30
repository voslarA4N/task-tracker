<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CUR-02, D-14: konzistencia stlpcov kurzu vo fakture. Kurz, zdroj a datum su vzdy spolu alebo vobec;
 * bez kurzu je suma v mene subjektu rovna sume faktury.
 */
final class AddInvoiceFxChecks extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE invoices ADD CONSTRAINT invoices_fx_pair_check CHECK ((fx_rate IS NULL) = (fx_source IS NULL) AND (fx_rate IS NULL) = (fx_date IS NULL))');
        $this->db->query('ALTER TABLE invoices ADD CONSTRAINT invoices_fx_total_check CHECK (fx_rate IS NOT NULL OR total_entity_ccy IS NULL OR total_entity_ccy = total)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_fx_total_check');
        $this->db->query('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_fx_pair_check');
    }
}
