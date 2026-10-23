<?php

declare(strict_types=1);

namespace App\Database\Support;

use InvalidArgumentException;

/**
 * Pre migracie (CodeIgniter\Database\Migration, $this->db).
 *
 * Predvolene su tabulky insert-only pre app_rw (default privileges v provision.sql).
 * grantMutable() pouzivat LEN pre tabulky, ktore nie su doklady.
 */
trait GrantsPrivileges
{
    protected function grantMutable(string $table): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException('Invalid table name: ' . $table);
        }

        $this->db->query('GRANT UPDATE, DELETE ON TABLE ' . $this->db->escapeIdentifiers($table) . ' TO app_rw');
    }
}
