<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

final class CreateClients extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE clients (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name text NOT NULL CHECK (char_length(name) BETWEEN 1 AND 200),
                address_line1 text NOT NULL,
                address_line2 text NULL,
                postal_code text NOT NULL,
                city text NOT NULL,
                country char(2) NOT NULL CHECK (country ~ '^[A-Z]{2}$'),
                identifier_type text NOT NULL CHECK (identifier_type IN ('local_id','eu_vat','other','none')),
                identifier_value text NULL,
                tax_id text NULL,
                vat_id text NULL,
                invoice_email text NOT NULL,
                cc_emails jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(cc_emails) = 'array'),
                hourly_rate numeric(10,2) NOT NULL CHECK (hourly_rate >= 0),
                currency char(3) NOT NULL CHECK (currency IN ('EUR','PLN','CZK')),
                doc_language text NULL CHECK (doc_language IN ('sk','pl','cs','en')),
                due_days smallint NULL CHECK (due_days BETWEEN 0 AND 365),
                portal_name text NULL CHECK (char_length(portal_name) <= 100),
                portal_url_template text NULL CHECK (char_length(portal_url_template) <= 500),
                billing_mode text NOT NULL CHECK (billing_mode IN ('domestic','eu_b2b','non_eu')),
                counts_toward_limit boolean NOT NULL DEFAULT true,
                archived_at timestamptz NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CHECK ((identifier_type = 'none') = (identifier_value IS NULL)),
                CHECK (billing_mode <> 'eu_b2b' OR vat_id IS NOT NULL)
            )
            SQL);
        $this->db->query('CREATE INDEX clients_name_idx ON clients (name)');
        $this->db->query("COMMENT ON TABLE clients IS 'Clients (CLI-01..07, D-12..D-15); no DELETE for app_rw, archive via archived_at'");
        // D-15: stĺpcový grant bez id a created_at, žiadny DELETE.
        $this->grantUpdateColumns('clients', [
            'name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'identifier_type',
            'identifier_value', 'tax_id', 'vat_id', 'invoice_email', 'cc_emails', 'hourly_rate', 'currency',
            'doc_language', 'due_days', 'portal_name', 'portal_url_template', 'billing_mode',
            'counts_toward_limit', 'archived_at', 'updated_at',
        ]);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS clients');
    }
}
