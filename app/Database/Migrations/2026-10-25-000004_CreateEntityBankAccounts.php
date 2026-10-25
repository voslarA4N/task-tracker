<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

final class CreateEntityBankAccounts extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE entity_bank_accounts (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                entity_id bigint NOT NULL REFERENCES entities (id),
                label text NOT NULL CHECK (char_length(label) BETWEEN 1 AND 60),
                iban text NOT NULL CHECK (iban ~ '^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$'),
                swift text NULL CHECK (swift ~ '^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$'),
                currency char(3) NOT NULL CHECK (currency IN ('EUR','PLN','CZK')),
                is_default boolean NOT NULL DEFAULT false,
                archived_at timestamptz NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CHECK (NOT (is_default AND archived_at IS NOT NULL))
            )
            SQL);
        $this->db->query('CREATE UNIQUE INDEX entity_bank_accounts_one_default ON entity_bank_accounts (entity_id) WHERE is_default AND archived_at IS NULL');
        $this->db->query('CREATE UNIQUE INDEX entity_bank_accounts_active_iban ON entity_bank_accounts (entity_id, iban) WHERE archived_at IS NULL');
        $this->db->query("COMMENT ON TABLE entity_bank_accounts IS 'Bank accounts per entity (ENT-06, D-06); archived, never deleted; currency and entity_id immutable for app_rw'");
        // T-03-43: zámerne bez currency a entity_id, bez DELETE.
        $this->grantUpdateColumns('entity_bank_accounts', ['label', 'iban', 'swift', 'is_default', 'archived_at', 'updated_at']);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS entity_bank_accounts');
    }
}
