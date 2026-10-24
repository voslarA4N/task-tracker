<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

final class CreateUserSecurity extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE user_security (
                user_id integer PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
                locale varchar(10) NOT NULL DEFAULT 'sk',
                auth_epoch integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL
            )
            SQL);
        $this->db->query("COMMENT ON TABLE user_security IS 'Per-user security state (locale, auth_epoch for server-side session invalidation); mutable for app_rw'");
        $this->grantMutable('user_security');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS user_security');
    }
}
