<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 2FA je volitelna (rozhodnutie vlastnika 2026-10-28, quick 261028-mf3): totp_declined_at si pamata,
 * ze pouzivatel ponuku 2FA odmietol alebo 2FA vypol, aby sa ponuka pri dalsom prihlaseni nezobrazila.
 * Nove opravnenie netreba: grantMutable('user_security') v CreateUserSecurity je tabulkove
 * GRANT UPDATE, DELETE, ktore pokryva aj nove stlpce.
 */
final class AddTotpDeclinedToUserSecurity extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE user_security ADD COLUMN totp_declined_at timestamptz NULL');
        $this->db->query("COMMENT ON COLUMN user_security.totp_declined_at IS 'User chose not to use 2FA (offer not shown again); NULL = undecided or 2FA active'");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE user_security DROP COLUMN totp_declined_at');
    }
}
