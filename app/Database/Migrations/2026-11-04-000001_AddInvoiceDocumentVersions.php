<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Faza 11.1: verzie dokumentov (D-01, D-02, D-04) a nastavenie lehoty pregenerovania (D-13).
 * Existujuce riadky dostavaju version = 1 konstantnym defaultom (metadata-only), immutable_guard sa nespusta.
 */
final class AddInvoiceDocumentVersions extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query('ALTER TABLE invoice_documents ADD COLUMN version integer NOT NULL DEFAULT 1 CHECK (version >= 1)');
        $this->db->query('ALTER TABLE invoice_documents DROP CONSTRAINT invoice_documents_invoice_id_kind_key');
        $this->db->query('ALTER TABLE invoice_documents ADD CONSTRAINT invoice_documents_invoice_kind_version_key UNIQUE (invoice_id, kind, version)');

        $this->db->query(<<<'SQL'
            CREATE FUNCTION invoice_documents_version_gapless() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.version <> coalesce((SELECT max(version) FROM invoice_documents WHERE invoice_id = NEW.invoice_id AND kind = NEW.kind), 0) + 1 THEN
                    RAISE EXCEPTION 'invoice_documents version must be max + 1 (no gaps), got %', NEW.version
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        $this->db->query(
            'CREATE TRIGGER invoice_documents_version_gapless BEFORE INSERT ON invoice_documents
             FOR EACH ROW EXECUTE FUNCTION invoice_documents_version_gapless()',
        );

        $this->db->query(
            'CREATE TABLE document_settings (
                id smallint PRIMARY KEY CHECK (id = 1),
                regen_invoice_hours integer NOT NULL DEFAULT 72 CHECK (regen_invoice_hours BETWEEN 0 AND 8760),
                revision integer NOT NULL DEFAULT 1 CHECK (revision >= 1),
                updated_at timestamptz NOT NULL
            )',
        );
        $this->db->query('INSERT INTO document_settings (id, regen_invoice_hours, revision, updated_at) VALUES (1, 72, 1, now())');
        $this->db->query('REVOKE INSERT, DELETE ON document_settings FROM app_rw');
        $this->grantUpdateColumns('document_settings', ['regen_invoice_hours', 'revision', 'updated_at']);
        $this->db->query("COMMENT ON TABLE document_settings IS 'Lehota pregenerovania PDF faktury v hodinach od vystavenia, 0 = nikdy (D-13); jediny riadok, app_rw smie iba UPDATE hodnot'");
    }

    public function down(): void
    {
        if ($this->db->query('SELECT 1 FROM invoice_documents WHERE version > 1 LIMIT 1')->getRowArray() !== null) {
            throw new RuntimeException('Cannot roll back: invoice_documents has regenerated versions.');
        }

        $this->db->query('DROP TABLE IF EXISTS document_settings');
        $this->db->query('DROP TRIGGER IF EXISTS invoice_documents_version_gapless ON invoice_documents');
        $this->db->query('DROP FUNCTION IF EXISTS invoice_documents_version_gapless()');
        $this->db->query('ALTER TABLE invoice_documents DROP CONSTRAINT invoice_documents_invoice_kind_version_key');
        $this->db->query('ALTER TABLE invoice_documents ADD CONSTRAINT invoice_documents_invoice_id_kind_key UNIQUE (invoice_id, kind)');
        $this->db->query('ALTER TABLE invoice_documents DROP COLUMN version');
    }
}
