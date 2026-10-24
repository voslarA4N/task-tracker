<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateAuthEvents extends Migration
{
    public function up(): void
    {
        // Denník prihlásení je insert-only (AUTH-07, Pasca 18): zámerne bez grantMutable().
        $this->db->query(<<<'SQL'
            CREATE TABLE auth_events (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                occurred_at timestamptz NOT NULL,
                user_id integer NULL REFERENCES users (id) ON DELETE SET NULL,
                event text NOT NULL CHECK (event ~ '^[a-z_]{3,40}$'),
                success boolean NOT NULL,
                ip text NOT NULL,
                user_agent text NULL,
                device_label text NULL,
                identifier_hash char(64) NULL,
                detail text NULL CHECK (length(detail) <= 200)
            )
            SQL);
        $this->db->query('CREATE INDEX auth_events_user_idx ON auth_events (user_id, occurred_at DESC)');
        $this->db->query('CREATE INDEX auth_events_time_idx ON auth_events (occurred_at)');
        $this->db->query("COMMENT ON TABLE auth_events IS 'Insert-only audit log of authentication events (AUTH-07); app_rw has no UPDATE/DELETE'");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS auth_events');
    }
}
