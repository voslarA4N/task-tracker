<?php

declare(strict_types=1);

namespace App\Database\Support;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Pre migracie (CodeIgniter\Database\Migration, $this->db).
 *
 * Predvolene su tabulky insert-only pre app_rw (default privileges v provision.sql).
 * Stlpcovy grant (grantUpdateColumns) je predvolena volba pre mutovatelne master tabulky,
 * grantMutable() pouzivat LEN pre ne-dokladove pomocne tabulky.
 */
trait GrantsPrivileges
{
    protected function grantMutable(string $table): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException('Invalid table name: ' . $table);
        }

        assert($this->db instanceof BaseConnection);
        $this->db->query('GRANT UPDATE, DELETE ON TABLE ' . $this->db->escapeIdentifiers($table) . ' TO app_rw');
    }

    /**
     * GRANT UPDATE len na vymenovane stlpce. DELETE sa nedava nikdy.
     *
     * @param list<string> $columns
     */
    protected function grantUpdateColumns(string $table, array $columns): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException('Invalid table name: ' . $table);
        }

        if ($columns === []) {
            throw new InvalidArgumentException('At least one column is required');
        }

        assert($this->db instanceof BaseConnection);
        $escaped = [];

        foreach ($columns as $column) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
                throw new InvalidArgumentException('Invalid column name: ' . $column);
            }
            $escaped[] = $this->db->escapeIdentifiers($column);
        }

        $this->db->query('GRANT UPDATE (' . implode(', ', $escaped) . ') ON TABLE ' . $this->db->escapeIdentifiers($table) . ' TO app_rw');
    }
}
