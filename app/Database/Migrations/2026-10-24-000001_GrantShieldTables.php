<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;

/**
 * Shield tabulky vytvara migracia Shieldu (2020-12-28), tu sa iba pridavaju granty pre app_rw.
 */
final class GrantShieldTables extends Migration
{
    use GrantsPrivileges;

    private const MUTABLE = ['users', 'auth_identities', 'auth_remember_tokens', 'auth_groups_users', 'auth_permissions_users'];

    public function up(): void
    {
        // auth_logins, auth_token_logins a settings zostavaju insert-only (bez UPDATE/DELETE):
        // denník prihlásení nesmie byť upraviteľný aplikáciou.
        foreach (self::MUTABLE as $table) {
            $this->grantMutable($table);
        }
    }

    public function down(): void
    {
        foreach (self::MUTABLE as $table) {
            assert($this->db instanceof BaseConnection);
            $this->db->query('REVOKE UPDATE, DELETE ON TABLE ' . $this->db->escapeIdentifiers($table) . ' FROM app_rw');
        }
    }
}
