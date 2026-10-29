<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

/**
 * D-27: identita odosielatela e-mailov na subjekte. NULL = predvolena hodnota (bez datovej migracie),
 * mail_bcc '[]' = vyslovne bez skrytej kopie. Nie je sucastou snapshotu dokladu.
 * app_rw: stlpcovy UPDATE len na tieto 4 stlpce (country/currency ostavaju zamknute).
 */
final class AddEntitiesMailSender extends Migration
{
    use GrantsPrivileges;

    private const COLUMNS = ['mail_from_name', 'mail_from_email', 'mail_reply_to', 'mail_bcc'];

    public function up(): void
    {
        $this->db->query('ALTER TABLE entities ADD COLUMN mail_from_name text NULL CHECK (mail_from_name IS NULL OR char_length(mail_from_name) BETWEEN 1 AND 100)');
        $this->db->query('ALTER TABLE entities ADD COLUMN mail_from_email text NULL CHECK (mail_from_email IS NULL OR char_length(mail_from_email) <= 254)');
        $this->db->query('ALTER TABLE entities ADD COLUMN mail_reply_to text NULL CHECK (mail_reply_to IS NULL OR char_length(mail_reply_to) <= 254)');
        $this->db->query("ALTER TABLE entities ADD COLUMN mail_bcc jsonb NULL CHECK (mail_bcc IS NULL OR jsonb_typeof(mail_bcc) = 'array')");

        $this->db->query("COMMENT ON COLUMN entities.mail_from_name IS 'Sender display name; NULL = trade name (D-27), not part of any snapshot'");
        $this->db->query("COMMENT ON COLUMN entities.mail_from_email IS 'Sender address, must be in email.allowedFromDomains; NULL = config fromEmail (D-27)'");
        $this->db->query("COMMENT ON COLUMN entities.mail_reply_to IS 'Reply-To address; NULL = entity e-mail (D-27)'");
        $this->db->query("COMMENT ON COLUMN entities.mail_bcc IS 'BCC archive list (json array); NULL = entity e-mail, [] = no BCC (D-27)'");

        $this->grantUpdateColumns('entities', self::COLUMNS);
    }

    public function down(): void
    {
        $this->db->query('REVOKE UPDATE (mail_from_name, mail_from_email, mail_reply_to, mail_bcc) ON TABLE entities FROM app_rw');

        foreach (self::COLUMNS as $column) {
            $this->db->query('ALTER TABLE entities DROP COLUMN IF EXISTS ' . $column);
        }
    }
}
