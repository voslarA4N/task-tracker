<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddTotpToUserSecurity extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE user_security ADD COLUMN totp_secret_enc text NULL, ADD COLUMN totp_enabled_at timestamptz NULL, ADD COLUMN totp_last_step bigint NULL');
        $this->db->query("COMMENT ON COLUMN user_security.totp_secret_enc IS 'Sodium secretbox, base64; nikdy otvorený text'");
        $this->db->query("COMMENT ON COLUMN user_security.totp_last_step IS 'Posledný použitý TOTP časový krok (replay ochrana, RFC 6238 5.2)'");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE user_security DROP COLUMN IF EXISTS totp_secret_enc, DROP COLUMN IF EXISTS totp_enabled_at, DROP COLUMN IF EXISTS totp_last_step');
    }
}
