<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

/**
 * SET-01, D-14: zakonne texty upravuje vlastnik cez UI. Pridava stlpec revision, limit znenia 2000 znakov
 * a opravnenia app_rw: INSERT a UPDATE len stlpcov body, provisional, revision, updated_at. DELETE nie.
 */
final class AddLegalTextsRevision extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query('ALTER TABLE legal_texts ADD COLUMN revision integer NOT NULL DEFAULT 1 CHECK (revision >= 1)');
        $this->replaceBodyCheck(2000);
        $this->db->query('GRANT INSERT ON legal_texts TO app_rw');
        $this->grantUpdateColumns('legal_texts', ['body', 'provisional', 'revision', 'updated_at']);
        $this->db->query("COMMENT ON TABLE legal_texts IS 'Legal wording per profile and language; app_rw may INSERT and UPDATE body, provisional, revision, updated_at only (D-14)'");
    }

    public function down(): void
    {
        $this->db->query('REVOKE UPDATE (body, provisional, revision, updated_at) ON legal_texts FROM app_rw');
        $this->db->query('REVOKE INSERT ON legal_texts FROM app_rw');
        $this->db->query('DELETE FROM legal_texts WHERE char_length(body) > 1000');
        $this->replaceBodyCheck(1000);
        $this->db->query('ALTER TABLE legal_texts DROP COLUMN revision');
        $this->db->query("COMMENT ON TABLE legal_texts IS 'Provisional legal wording per profile and language; read-only for app_rw (D-17)'");
    }

    private function replaceBodyCheck(int $max): void
    {
        $rows = $this->db->query(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'legal_texts'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%char_length(body)%'",
        )->getResultArray();

        foreach ($rows as $row) {
            $this->db->query('ALTER TABLE legal_texts DROP CONSTRAINT "' . str_replace('"', '""', (string) $row['conname']) . '"');
        }

        $this->db->query('ALTER TABLE legal_texts ADD CONSTRAINT legal_texts_body_check CHECK (char_length(body) BETWEEN 1 AND ' . $max . ')');
    }
}
