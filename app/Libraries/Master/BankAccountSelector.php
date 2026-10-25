<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use App\Libraries\Platform\DbBool;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Vyber uctu pre fakturu: ucet v mene faktury, inak predvoleny, inak NoBankAccount (D-06).
 */
final class BankAccountSelector
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

    /**
     * @return array<string, mixed> riadok uctu + currency_match
     *
     * @throws NoBankAccount
     */
    public function forCurrency(int $entityId, string $currency): array
    {
        $row = $this->db->query(
            'SELECT * FROM entity_bank_accounts WHERE entity_id = :e: AND currency = :c: AND archived_at IS NULL ORDER BY is_default DESC, id ASC LIMIT 1',
            ['e' => $entityId, 'c' => $currency],
        )->getRowArray();

        $match = $row !== null;

        if ($row === null) {
            $row = $this->db->query(
                'SELECT * FROM entity_bank_accounts WHERE entity_id = :e: AND is_default AND archived_at IS NULL LIMIT 1',
                ['e' => $entityId],
            )->getRowArray();
        }

        if ($row === null) {
            throw new NoBankAccount('Entity ' . $entityId . ' has no active bank account');
        }

        $row['is_default']     = DbBool::of($row['is_default']);
        $row['currency']       = trim((string) $row['currency']);
        $row['currency_match'] = $match;

        return $row;
    }
}
