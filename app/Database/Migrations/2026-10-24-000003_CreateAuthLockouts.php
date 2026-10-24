<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

final class CreateAuthLockouts extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE auth_lockouts (
                kind text NOT NULL CHECK (kind IN ('ip', 'account')),
                subject text NOT NULL,
                fail_count integer NOT NULL,
                window_start timestamptz NOT NULL,
                locked_until timestamptz NULL,
                PRIMARY KEY (kind, subject)
            )
            SQL);
        $this->db->query("COMMENT ON TABLE auth_lockouts IS 'Failed-login counters per IP (/64 for IPv6) and per account (sha256 of e-mail); mutable for app_rw (AUTH-04)'");
        $this->grantMutable('auth_lockouts');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS auth_lockouts');
    }
}
