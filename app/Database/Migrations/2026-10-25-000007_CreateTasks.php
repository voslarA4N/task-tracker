<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

final class CreateTasks extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE tasks (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                client_id bigint NOT NULL REFERENCES clients(id),
                url text NULL CHECK (char_length(url) <= 2000),
                title text NOT NULL CHECK (char_length(title) BETWEEN 1 AND 300),
                hours numeric(7,2) NOT NULL CHECK (hours > 0),
                rate numeric(12,2) NOT NULL CHECK (rate >= 0),
                note text NULL CHECK (char_length(note) <= 2000),
                status text NOT NULL DEFAULT 'in_progress' CHECK (status IN ('in_progress','done')),
                done_at timestamptz NULL,
                import_key text NULL CHECK (import_key ~ '^[0-9a-f]{64}$'),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CHECK ((status = 'done') = (done_at IS NOT NULL))
            )
            SQL);
        $this->db->query('CREATE UNIQUE INDEX tasks_import_key_uq ON tasks (import_key) WHERE import_key IS NOT NULL');
        $this->db->query('CREATE INDEX tasks_client_status_idx ON tasks (client_id, status)');
        $this->db->query('CREATE INDEX tasks_created_idx ON tasks (created_at DESC, id DESC)');
        $this->db->query("COMMENT ON TABLE tasks IS 'Tasks (D-01, TSK-03); billing state is derived in task_billing_v, never stored; delete blocked by FK RESTRICT from billing_allocations'");
        // import_key a created_at sa menit nedaju.
        $this->grantUpdateColumns('tasks', ['client_id', 'url', 'title', 'hours', 'rate', 'note', 'status', 'done_at', 'updated_at']);
        $this->grantDelete('tasks');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS tasks');
    }
}
