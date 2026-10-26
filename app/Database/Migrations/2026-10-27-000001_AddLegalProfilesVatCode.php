<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Kod DPH pre polozky faktury (INV-09, D-09). Hodnoty su PREDBEZNE (riziko R1, predpoklad A1):
 * potvrdi ich uctovnicka, oprava sa robi novou migraciou, nikdy upravou tejto.
 * Tabulka ostava pre app_rw read-only (bez grantu).
 */
final class AddLegalProfilesVatCode extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE legal_profiles ADD COLUMN vat_code text NULL CHECK (vat_code ~ '^[a-z0-9 ]{1,12}$')");
        $this->db->query("UPDATE legal_profiles SET vat_code = 'zw' WHERE country = 'PL' AND billing_mode IS NULL AND vat_regime IN ('pl_exempt_113_1', 'pl_exempt_113_9')");
        $this->db->query("UPDATE legal_profiles SET vat_code = 'np' WHERE country = 'PL' AND billing_mode = 'eu_b2b'");
        $this->db->query("UPDATE legal_profiles SET vat_code = 'np' WHERE vat_regime = 'sk_non_vat'");
        $this->db->query("UPDATE legal_profiles SET vat_code = 'np' WHERE vat_regime = 'cz_non_vat'");
        $this->db->query("COMMENT ON COLUMN legal_profiles.vat_code IS 'KSeF FA(3) P_12 code per line (M1); PROVISIONAL, confirmed by accountant (R1)'");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE legal_profiles DROP COLUMN IF EXISTS vat_code');
    }
}
