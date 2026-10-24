<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

final class CreateBackupCodes extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE backup_codes (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                user_id integer NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                code_hash char(64) NOT NULL,
                created_at timestamptz NOT NULL,
                used_at timestamptz NULL,
                UNIQUE (user_id, code_hash)
            )
            SQL);
        $this->db->query("COMMENT ON TABLE backup_codes IS 'Záložné kódy 2FA: HMAC-SHA256 s pepperom z .env, nikdy otvorený text; jednorazové (used_at); mutable pre app_rw (AUTH-03)'");
        $this->grantMutable('backup_codes');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS backup_codes');
    }
}
