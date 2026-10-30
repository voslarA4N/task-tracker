<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Zoznam podporovanych mien z tabulky currencies (CUR-01, D-01).
 */
final class CurrencyRepository
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    /**
     * @var list<string>|null
     */
    private ?array $codes = null;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Aktivne meny podla sort_order.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        if ($this->codes === null) {
            $rows        = $this->db->query('SELECT code FROM currencies WHERE active ORDER BY sort_order, code')->getResultArray();
            $this->codes = array_map(static fn (array $r): string => trim((string) $r['code']), $rows);
        }

        return $this->codes;
    }

    public function isActive(string $code): bool
    {
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            return false;
        }

        return $this->db->query('SELECT 1 FROM currencies WHERE code = :code: AND active', ['code' => $code])->getRowArray() !== null;
    }

    public function exists(string $code): bool
    {
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            return false;
        }

        return $this->db->query('SELECT 1 FROM currencies WHERE code = :code:', ['code' => $code])->getRowArray() !== null;
    }

    public function decimals(string $code): int
    {
        $row = $this->db->query('SELECT decimals FROM currencies WHERE code = :code:', ['code' => $code])->getRowArray();

        return $row === null ? 2 : (int) $row['decimals'];
    }
}
