<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

final class CreateTrustedDevices extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE trusted_devices (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                user_id integer NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash char(64) NOT NULL UNIQUE,
                prev_token_hash char(64) NULL,
                rotated_at timestamptz NULL,
                label text NOT NULL,
                user_agent text NULL,
                ip_first text NOT NULL,
                created_at timestamptz NOT NULL,
                last_used_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                revoked_at timestamptz NULL,
                revoked_reason text NULL
            )
            SQL);
        $this->db->query('CREATE INDEX trusted_devices_user_id_idx ON trusted_devices (user_id)');
        $this->db->query("COMMENT ON TABLE trusted_devices IS 'Dôveryhodné zariadenia (D-15..D-17): v DB iba sha256 tokenu cookie, pevná expires_at (30 dní od TOTP, nepredlžuje sa), odvolateľné; mutable pre app_rw (AUTH-05)'");
        $this->grantMutable('trusted_devices');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS trusted_devices');
    }
}
