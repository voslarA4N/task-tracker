<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

/**
 * CUR-02, CUR-05, D-05: nastavenie zdroja kurzu, zakladu datumu a pravidla dna podla krajiny subjektu.
 * app_rw smie menit iba hodnoty nastavenia, nie pridavat ani mazat krajiny (T-08-15).
 */
final class CreateFxCountrySettings extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(
            "CREATE TABLE fx_country_settings (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                country char(2) NOT NULL UNIQUE CHECK (country IN ('SK','PL','CZ')),
                source text NOT NULL CHECK (source IN ('NBP','ECB','CNB')),
                date_basis text NOT NULL CHECK (date_basis IN ('delivery_date','issue_date')),
                day_rule text NOT NULL CHECK (day_rule IN ('previous_business_day','on_or_before')),
                revision integer NOT NULL DEFAULT 1 CHECK (revision >= 1),
                updated_at timestamptz NOT NULL
            )",
        );
        $this->db->query(
            "INSERT INTO fx_country_settings (country, source, date_basis, day_rule, revision, updated_at) VALUES
                ('SK', 'ECB', 'delivery_date', 'previous_business_day', 1, now()),
                ('PL', 'NBP', 'delivery_date', 'previous_business_day', 1, now()),
                ('CZ', 'CNB', 'delivery_date', 'on_or_before', 1, now())",
        );
        $this->db->query('REVOKE INSERT, DELETE ON fx_country_settings FROM app_rw');
        $this->grantUpdateColumns('fx_country_settings', ['source', 'date_basis', 'day_rule', 'revision', 'updated_at']);
        $this->db->query("COMMENT ON TABLE fx_country_settings IS 'FX source, date basis and day rule per entity country (D-05). Defaults are provisional until the accountant confirms them (risk R1); app_rw may UPDATE values only'");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS fx_country_settings');
    }
}
