<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateAuditLog extends Migration
{
    public function up(): void
    {
        // Denník zmien je insert-only (AUD-01, D-18): zámerne bez grantMutable() aj grantUpdateColumns().
        $this->db->query(<<<'SQL'
            CREATE TABLE audit_log (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                occurred_at timestamptz NOT NULL,
                user_id integer NULL REFERENCES users (id) ON DELETE SET NULL,
                entity_type text NOT NULL CHECK (entity_type ~ '^[a-z_]{3,40}$'),
                entity_id bigint NOT NULL,
                action text NOT NULL CHECK (action ~ '^[a-z_]{3,40}$'),
                before_data jsonb NULL,
                after_data jsonb NULL,
                ip text NOT NULL
            )
            SQL);
        $this->db->query('CREATE INDEX audit_log_entity_idx ON audit_log (entity_type, entity_id, occurred_at DESC)');
        $this->db->query("COMMENT ON TABLE audit_log IS 'Append-only change log (AUD-01, D-18); app_rw has no UPDATE/DELETE'");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS audit_log');
    }
}
