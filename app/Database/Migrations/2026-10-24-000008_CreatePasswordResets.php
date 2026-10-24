<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

final class CreatePasswordResets extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE password_resets (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                user_id integer NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash char(64) NOT NULL UNIQUE,
                ip text NOT NULL,
                created_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                used_at timestamptz NULL
            )
            SQL);
        $this->db->query('CREATE INDEX password_resets_user_id_idx ON password_resets (user_id)');
        $this->db->query("COMMENT ON TABLE password_resets IS 'Tokeny obnovy hesla (AUTH-06): v DB iba sha256, platnosť 60 minút, jednorazové (used_at); mutable pre app_rw'");
        $this->grantMutable('password_resets');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS password_resets');
    }
}
