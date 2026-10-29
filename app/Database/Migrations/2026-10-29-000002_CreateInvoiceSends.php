<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

/**
 * Evidencia odoslani faktur (Phase 7, D-10, D-29). Insert-only: grant len SELECT, INSERT pre app_rw,
 * immutable_guard (aj pre ownera) a truncate_guard. Storno rucneho oznacenia je samostatny zaznam v invoice_send_voids.
 *
 * Rozsiruje aj invoice_status_v (Phase 7: len pripojene stlpce na koniec, Pitfall 6; Phase 9 doplni cancelled).
 * Stav Odoslana (D-29) sa odvodzuje z invoice_sends bez zrusenych rucnych oznaceni, bez casu v definicii.
 * Pohlad je zamerne v tej istej migracii: vo vyvojovom prostredi (WSL2 9p + musl) SPL iterator prestane
 * vidiet adresar app/Database/Migrations, ak ma viac ako 30 suborov, takze dalsi subor by rozbil `spark migrate`.
 */
final class CreateInvoiceSends extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE invoice_sends (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                invoice_id bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
                kind text NOT NULL CHECK (kind IN ('invoice','resend','reminder','manual')),
                sent_at timestamptz NOT NULL,
                recipients jsonb NOT NULL DEFAULT '{"to":[],"cc":[]}' CHECK (jsonb_typeof(recipients) = 'object'),
                bcc jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(bcc) = 'array'),
                from_email text NULL,
                from_name text NULL,
                reply_to text NULL,
                subject text NULL CHECK (subject IS NULL OR char_length(subject) <= 200),
                body_sha256 char(64) NULL,
                attachments jsonb NOT NULL DEFAULT '[]',
                message_id text NULL UNIQUE,
                in_reply_to text NULL,
                smtp_response text NULL CHECK (smtp_response IS NULL OR char_length(smtp_response) <= 500),
                transport text NOT NULL CHECK (transport IN ('smtp','log','none')),
                counts_as_sent boolean NOT NULL,
                note text NULL CHECK (note IS NULL OR char_length(note) <= 200),
                request_key uuid NULL UNIQUE,
                created_at timestamptz NOT NULL,
                created_by integer NULL REFERENCES users(id),
                CHECK ((kind = 'manual') = (transport = 'none'))
            )
            SQL);
        $this->db->query('CREATE INDEX invoice_sends_invoice_idx ON invoice_sends (invoice_id, sent_at)');

        $this->db->query(<<<'SQL'
            CREATE TABLE invoice_send_voids (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                send_id bigint NOT NULL UNIQUE REFERENCES invoice_sends(id) ON DELETE RESTRICT,
                reason text NOT NULL CHECK (char_length(btrim(reason)) BETWEEN 1 AND 200),
                voided_at timestamptz NOT NULL,
                voided_by integer NULL REFERENCES users(id)
            )
            SQL);

        foreach (['invoice_sends', 'invoice_send_voids'] as $t) {
            $this->db->query("CREATE TRIGGER {$t}_immutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION immutable_guard()");
            $this->db->query("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION truncate_guard()");
            $this->db->query("GRANT SELECT, INSERT ON TABLE {$t} TO app_rw");
        }

        $this->extendStatusView();

        $this->db->query("COMMENT ON TABLE invoice_sends IS 'Sent e-mails and manual sent marks (D-10, D-29); insert-only, state Odoslana derived in invoice_status_v'");
        $this->db->query("COMMENT ON TABLE invoice_send_voids IS 'Void of a manual sent mark (D-29); insert-only'");
    }

    public function down(): void
    {
        $this->restoreOriginalStatusView();
        foreach (['invoice_send_voids', 'invoice_sends'] as $t) {
            $this->db->query("DROP TRIGGER IF EXISTS {$t}_no_truncate ON {$t}");
            $this->db->query("DROP TRIGGER IF EXISTS {$t}_immutable ON {$t}");
        }
        $this->db->query('DROP TABLE IF EXISTS invoice_send_voids');
        $this->db->query('DROP TABLE IF EXISTS invoice_sends');
    }

    public function extendStatusView(): void
    {
        $this->db->query(<<<'SQL'
            CREATE OR REPLACE VIEW invoice_status_v AS
            SELECT i.id, i.origin, i.type, i.number, i.external_ref, i.client_id, i.entity_id, i.billing_month, i.issue_date, i.due_date,
                   i.currency, i.total, i.counts_toward_limit, i.line_count, i.created_at,
                   CASE WHEN i.origin = 'external' THEN 'external' WHEN s.sent_at IS NOT NULL THEN 'sent' ELSE 'issued' END AS status,
                   s.sent_at AS sent_at,
                   COALESCE(s.send_count, 0)::integer AS send_count,
                   s.last_reminder_at AS last_reminder_at
            FROM invoices i
            LEFT JOIN (
                SELECT x.invoice_id,
                       MAX(x.sent_at) FILTER (WHERE x.counts_as_sent) AS sent_at,
                       COUNT(*) FILTER (WHERE x.kind IN ('invoice','resend')) AS send_count,
                       MAX(x.sent_at) FILTER (WHERE x.kind = 'reminder') AS last_reminder_at
                FROM invoice_sends x
                WHERE NOT EXISTS (SELECT 1 FROM invoice_send_voids v WHERE v.send_id = x.id)
                GROUP BY x.invoice_id
            ) s ON s.invoice_id = i.id
            SQL);
        $this->db->query('GRANT SELECT ON invoice_status_v TO app_rw');
    }

    /**
     * Pohlad nemozno zuzit cez CREATE OR REPLACE, preto DROP + povodna definicia z 2026-10-27-000004.
     */
    public function restoreOriginalStatusView(): void
    {
        $this->db->query('DROP VIEW IF EXISTS invoice_status_v');
        $this->db->query(<<<'SQL'
            CREATE VIEW invoice_status_v AS
            SELECT id, origin, type, number, external_ref, client_id, entity_id, billing_month, issue_date, due_date,
                   currency, total, counts_toward_limit, line_count, created_at,
                   CASE WHEN origin = 'external' THEN 'external' ELSE 'issued' END AS status
            FROM invoices
            SQL);
        $this->db->query('GRANT SELECT ON invoice_status_v TO app_rw');
    }
}
