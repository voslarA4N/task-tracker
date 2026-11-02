<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

/**
 * LIM-01, LIM-05, D-01, D-02, D-04, D-06, D-07: limity obratu ako data v DB (viac riadkov na krajinu a rok)
 * a pociatocny obrat subjektu. Seed riadky su neoverene navrhy (is_seed), hodnoty nie su nikde v kode.
 * app_rw smie menit a mazat riadky nastaveni, nikdy doklady.
 */
final class CreateTurnoverLimits extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(
            "CREATE TABLE turnover_limits (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                country char(2) NOT NULL CHECK (country IN ('SK','PL','CZ')),
                year smallint NOT NULL CHECK (year BETWEEN 2000 AND 2100),
                name text NOT NULL CHECK (char_length(btrim(name)) BETWEEN 1 AND 120),
                amount numeric(12,2) NOT NULL CHECK (amount > 0),
                currency char(3) NOT NULL REFERENCES currencies(code),
                calc_method text NOT NULL CHECK (calc_method IN ('calendar_year','rolling_12m','pro_rata_first_year')),
                thresholds integer[] NOT NULL DEFAULT '{80,100}' CHECK (cardinality(thresholds) BETWEEN 1 AND 3 AND array_position(thresholds, NULL) IS NULL),
                is_seed boolean NOT NULL DEFAULT false,
                revision integer NOT NULL DEFAULT 1 CHECK (revision >= 1),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CONSTRAINT turnover_limits_name_uq UNIQUE (country, year, name)
            )",
        );

        $this->db->query(
            "INSERT INTO turnover_limits (country, year, name, amount, currency, calc_method, thresholds, is_seed, created_at, updated_at)
             SELECT v.country, v.year, v.name, v.amount, v.currency, v.calc_method, CAST(v.thresholds AS integer[]), true, now(), now()
             FROM (VALUES
                ('SK', 2026, 'Registrácia DPH (kalendárny rok)', 50000.00, 'EUR', 'calendar_year', '{80,100}'),
                ('SK', 2026, 'Registrácia DPH (12 mesiacov)', 49790.00, 'EUR', 'rolling_12m', '{80,100}'),
                ('SK', 2026, 'Okamžitá registrácia', 62500.00, 'EUR', 'calendar_year', '{100}'),
                ('PL', 2026, 'Zwolnienie podmiotowe (art. 113)', 240000.00, 'PLN', 'pro_rata_first_year', '{80,100}')
             ) AS v(country, year, name, amount, currency, calc_method, thresholds)
             WHERE NOT EXISTS (SELECT 1 FROM turnover_limits)",
        );

        $this->db->query(
            "CREATE TABLE entity_opening_turnover (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                entity_id bigint NOT NULL REFERENCES entities(id),
                year smallint NOT NULL CHECK (year BETWEEN 2000 AND 2100),
                amount numeric(12,2) NOT NULL CHECK (amount >= 0),
                note text NULL CHECK (note IS NULL OR char_length(note) <= 255),
                revision integer NOT NULL DEFAULT 1 CHECK (revision >= 1),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CONSTRAINT entity_opening_turnover_uq UNIQUE (entity_id, year)
            )",
        );

        $this->grantUpdateColumns('turnover_limits', ['country', 'year', 'name', 'amount', 'currency', 'calc_method', 'thresholds', 'is_seed', 'revision', 'updated_at']);
        $this->grantDelete('turnover_limits');
        $this->grantUpdateColumns('entity_opening_turnover', ['amount', 'note', 'revision', 'updated_at']);
        $this->grantDelete('entity_opening_turnover');

        $this->db->query("CREATE INDEX invoices_turnover_idx ON invoices (entity_id, turnover_date) WHERE counts_toward_limit AND origin = 'issued'");

        $this->db->query("COMMENT ON TABLE turnover_limits IS 'Turnover limits per country and year; values are owner-editable settings, seeded rows are unverified proposals (is_seed)'");
        $this->db->query("COMMENT ON TABLE entity_opening_turnover IS 'Opening turnover per entity and year (turnover before the app was used); owner-editable setting added to document turnover'");
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS invoices_turnover_idx');
        $this->db->query('DROP TABLE IF EXISTS entity_opening_turnover');
        $this->db->query('DROP TABLE IF EXISTS turnover_limits');
    }
}
