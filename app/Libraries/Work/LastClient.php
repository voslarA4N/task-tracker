<?php

declare(strict_types=1);

namespace App\Libraries\Work;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Predvoleny klient pre novu ulohu (D-02): klient poslednej ulohy, ak nie je archivovany,
 * inak prvy nearchivovany podla nazvu. Odvodene z dat, bez novej tabulky.
 */
final class LastClient
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function resolve(): ?int
    {
        $last = $this->db->query(
            'SELECT c.id, c.archived_at FROM tasks t JOIN clients c ON c.id = t.client_id
             ORDER BY t.created_at DESC, t.id DESC LIMIT 1',
        )->getRow();

        if ($last !== null && $last->archived_at === null) {
            return (int) $last->id;
        }

        $first = $this->db->query('SELECT id FROM clients WHERE archived_at IS NULL ORDER BY name, id LIMIT 1')->getRow();

        return $first === null ? null : (int) $first->id;
    }
}
