<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

final class CreateEntities extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE entities (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name_internal text NOT NULL CHECK (char_length(name_internal) BETWEEN 1 AND 100),
                country char(2) NOT NULL CHECK (country IN ('SK','PL','CZ')),
                currency char(3) NOT NULL CHECK (currency IN ('EUR','PLN','CZK')),
                active_from date NOT NULL,
                active_to date NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CHECK (active_to IS NULL OR active_to >= active_from)
            )
            SQL);
        $this->db->query("COMMENT ON TABLE entities IS 'Invoicing entities; country and currency are immutable for app_rw (ENT-07, D-02), no DELETE'");
        // D-02, ENT-07: zámerne bez country a currency, bez grantMutable().
        $this->grantUpdateColumns('entities', ['name_internal', 'active_from', 'active_to', 'updated_at']);

        $this->db->query(<<<'SQL'
            CREATE TABLE entity_versions (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                entity_id bigint NOT NULL REFERENCES entities (id),
                valid_from date NOT NULL,
                trade_name text NOT NULL,
                address_line1 text NOT NULL,
                address_line2 text NULL,
                postal_code text NOT NULL,
                city text NOT NULL,
                company_id text NULL,
                regon text NULL,
                tax_id text NULL,
                vat_id text NULL,
                registry_note text NULL,
                email text NOT NULL,
                phone text NULL,
                doc_language text NOT NULL CHECK (doc_language IN ('sk','pl','cs','en')),
                vat_regime text NOT NULL CHECK (vat_regime IN ('sk_non_vat','pl_exempt_113_1','pl_exempt_113_9','cz_non_vat')),
                due_days smallint NOT NULL CHECK (due_days BETWEEN 0 AND 365),
                created_at timestamptz NOT NULL,
                created_by integer NULL REFERENCES users (id) ON DELETE SET NULL,
                UNIQUE (entity_id, valid_from)
            )
            SQL);
        // Insert-only: zámerne bez akéhokoľvek UPDATE/DELETE grantu.
        $this->db->query("COMMENT ON TABLE entity_versions IS 'Insert-only versions (ENT-03, D-04)'");

        $this->db->query(<<<'SQL'
            CREATE TABLE invoice_series (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                entity_id bigint NOT NULL REFERENCES entities (id),
                kind text NOT NULL CHECK (kind IN ('invoice','credit_note')),
                format text NOT NULL CHECK (char_length(format) BETWEEN 1 AND 40),
                initial_number integer NOT NULL DEFAULT 1 CHECK (initial_number >= 1),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (entity_id, kind)
            )
            SQL);
        $this->db->query("COMMENT ON TABLE invoice_series IS 'Numbering series per entity (D-10); only format and initial_number are mutable'");
        $this->grantUpdateColumns('invoice_series', ['format', 'initial_number', 'updated_at']);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS invoice_series');
        $this->db->query('DROP TABLE IF EXISTS entity_versions');
        $this->db->query('DROP TABLE IF EXISTS entities');
    }
}
