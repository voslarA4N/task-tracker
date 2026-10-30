<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

/**
 * D-07, D-09: cache stiahnutych kurzov kluceovana zdroj + mena + bound_date. Pomocka pre nahlad;
 * vystavena faktura sa na nu nespolieha. app_rw nesmie mazat (T-08-13).
 */
final class CreateExchangeRateCache extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(
            "CREATE TABLE exchange_rate_cache (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                source text NOT NULL CHECK (source IN ('NBP','ECB','CNB')),
                currency char(3) NOT NULL REFERENCES currencies(code),
                bound_date date NOT NULL,
                valid_for date NOT NULL CHECK (valid_for <= bound_date),
                rate numeric(18,8) NOT NULL CHECK (rate > 0),
                rate_published text NOT NULL CHECK (rate_published ~ '^[0-9]{1,10}(\\.[0-9]{1,8})?$'),
                amount integer NOT NULL DEFAULT 1 CHECK (amount >= 1),
                table_no text NULL CHECK (table_no IS NULL OR char_length(table_no) <= 40),
                direction text NOT NULL CHECK (direction IN ('multiply','divide')),
                fetched_at timestamptz NOT NULL,
                UNIQUE (source, currency, bound_date)
            )",
        );
        $this->grantUpdateColumns('exchange_rate_cache', ['valid_for', 'rate', 'rate_published', 'amount', 'table_no', 'direction', 'fetched_at']);
        $this->db->query("COMMENT ON TABLE exchange_rate_cache IS 'Helper cache for the preview; an invoice does not rely on it, the snapshot is the source of truth (D-07). No DELETE for app_rw'");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS exchange_rate_cache');
    }
}
