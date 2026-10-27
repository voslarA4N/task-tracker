<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WR-07 (review Phase 5): nemennost faktur a citacov aj voci TRUNCATE (aj pre ownera).
 * Riadkove triggery TRUNCATE neodchytia, preto statement trigger BEFORE TRUNCATE. Prazdnu tabulku
 * zmazat smie (nic sa nestrati, napr. TRUNCATE users CASCADE v testoch). Zamerny zasah ownera
 * vyzaduje vypnut trigger (ALTER TABLE ... DISABLE TRIGGER), co je viditelny a auditovatelny krok.
 */
final class GuardInvoiceTruncate extends Migration
{
    public const TABLES = ['invoices', 'invoice_lines', 'invoice_documents', 'invoice_counters'];

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE FUNCTION truncate_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_rows boolean;
            BEGIN
                EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I.%I)', TG_TABLE_SCHEMA, TG_TABLE_NAME) INTO v_rows;
                IF v_rows THEN
                    RAISE EXCEPTION '% is immutable', TG_TABLE_NAME USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NULL;
            END;
            $$
            SQL);

        foreach (self::TABLES as $t) {
            $this->db->query("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION truncate_guard()");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            $this->db->query("DROP TRIGGER IF EXISTS {$t}_no_truncate ON {$t}");
        }
        $this->db->query('DROP FUNCTION IF EXISTS truncate_guard()');
    }
}
