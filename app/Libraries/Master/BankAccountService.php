<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\DbBool;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

/**
 * Bankove ucty subjektu (ENT-06, D-06): archivacia namiesto mazania, najviac jeden predvoleny aktivny ucet.
 * Kazda metoda pracuje s dvojicou (entityId, accountId) kvoli ochrane pred IDOR (T-03-42).
 */
final class BankAccountService
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly AuditLogger $audit;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?AuditLogger $audit = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = $audit ?? new AuditLogger($this->db);
    }

    /**
     * @param array<string, mixed> $v normalizovane hodnoty (BankAccountInput::normalize)
     */
    public function add(int $entityId, array $v): int
    {
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($entityId, $v, $now): int {
            $this->lockEntity($entityId);

            $active = (int) $this->db->query(
                'SELECT count(*) AS c FROM entity_bank_accounts WHERE entity_id = :e: AND archived_at IS NULL',
                ['e' => $entityId],
            )->getRow()->c;

            $default    = $active === 0 || (bool) ($v['is_default'] ?? false);
            $oldDefault = $default ? $this->currentDefaultId($entityId) : null;

            if ($default) {
                $this->clearDefault($entityId, $now);
            }

            $row = [
                'entity_id'  => $entityId,
                'label'      => (string) $v['label'],
                'iban'       => (string) $v['iban'],
                'swift'      => ($v['swift'] ?? '') === '' ? null : (string) $v['swift'],
                'currency'   => (string) $v['currency'],
                'is_default' => DbBool::toDb($default),
                'now'        => $now,
            ];

            $id = (int) $this->db->query(
                'INSERT INTO entity_bank_accounts (entity_id, label, iban, swift, currency, is_default, created_at, updated_at) '
                . 'VALUES (:entity_id:, :label:, :iban:, :swift:, :currency:, CAST(:is_default: AS boolean), :now:, :now:) RETURNING id',
                $row,
            )->getRow()->id;

            $this->audit->record('bank_account', $id, 'create', null, [
                'id'         => $id,
                'entity_id'  => $entityId,
                'label'      => $row['label'],
                'iban'       => $row['iban'],
                'swift'      => $row['swift'],
                'currency'   => $row['currency'],
                'is_default' => $default,
            ]);

            if ($oldDefault !== null) {
                // Zrušenie predošlého predvoleného účtu musí byť v audite rovnako ako pri setDefault (WR-04).
                $this->audit->record('bank_account', $id, 'set_default', ['default_account_id' => $oldDefault], ['default_account_id' => $id]);
            }

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $v label, iban, swift, is_default
     */
    public function update(int $entityId, int $accountId, array $v): bool
    {
        $new = [
            'label' => (string) $v['label'],
            'iban'  => (string) $v['iban'],
            'swift' => ($v['swift'] ?? '') === '' ? null : (string) $v['swift'],
        ];
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($entityId, $accountId, $v, $new, $now): bool {
            // Rozhodnutia aj audit "before" nad uzamknutým stavom v tej istej transakcii (WR-02).
            $before = $this->lockOrFail($entityId, $accountId);
            $old    = [
                'label' => (string) $before['label'],
                'iban'  => (string) $before['iban'],
                'swift' => $before['swift'] === null ? null : (string) $before['swift'],
            ];
            $promote = (bool) ($v['is_default'] ?? false) && ! $before['is_default'] && $before['archived_at'] === null;
            $changed = false;

            if ($old !== $new) {
                $this->db->query(
                    'UPDATE entity_bank_accounts SET label = :l:, iban = :i:, swift = :s:, updated_at = :now: WHERE id = :a: AND entity_id = :e:',
                    ['l' => $new['label'], 'i' => $new['iban'], 's' => $new['swift'], 'now' => $now, 'a' => $accountId, 'e' => $entityId],
                );
                $changed = $this->audit->recordChange('bank_account', $accountId, 'update', $old, $new);
            }

            if ($promote) {
                $this->applyDefault($entityId, $accountId, $now);
                $changed = true;
            }

            return $changed;
        });
    }

    public function setDefault(int $entityId, int $accountId): void
    {
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        Transaction::run($this->db, function () use ($entityId, $accountId, $now): void {
            $account = $this->lockOrFail($entityId, $accountId);

            if ($account['archived_at'] !== null) {
                throw new InvalidArgumentException('Archived account cannot be default');
            }

            if ($account['is_default']) {
                return;
            }

            $this->applyDefault($entityId, $accountId, $now);
        });
    }

    public function archive(int $entityId, int $accountId): void
    {
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        Transaction::run($this->db, function () use ($entityId, $accountId, $now): void {
            $account = $this->lockOrFail($entityId, $accountId);

            if ($account['archived_at'] !== null) {
                return;
            }

            $this->db->query(
                'UPDATE entity_bank_accounts SET archived_at = :now:, is_default = false, updated_at = :now: WHERE id = :a: AND entity_id = :e: AND archived_at IS NULL',
                ['now' => $now, 'a' => $accountId, 'e' => $entityId],
            );
            $this->audit->record('bank_account', $accountId, 'archive', ['archived_at' => null], ['archived_at' => $now]);

            if ($account['is_default']) {
                $next = $this->db->query(
                    'SELECT MIN(id) AS id FROM entity_bank_accounts WHERE entity_id = :e: AND archived_at IS NULL',
                    ['e' => $entityId],
                )->getRow();

                if ($next !== null && $next->id !== null) {
                    $this->applyDefault($entityId, (int) $next->id, $now, $accountId);
                }
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFor(int $entityId, bool $archived): array
    {
        $sql = $archived
            ? 'SELECT * FROM entity_bank_accounts WHERE entity_id = :e: AND archived_at IS NOT NULL ORDER BY archived_at DESC, id DESC'
            : 'SELECT * FROM entity_bank_accounts WHERE entity_id = :e: AND archived_at IS NULL ORDER BY is_default DESC, id';

        return array_map(
            fn (array $row): array => $this->hydrate($row),
            $this->db->query($sql, ['e' => $entityId])->getResultArray(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $entityId, int $accountId): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM entity_bank_accounts WHERE id = :a: AND entity_id = :e:',
            ['a' => $accountId, 'e' => $entityId],
        )->getRowArray();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Zámok riadku subjektu serializuje všetky zmeny jeho účtov (predvolený účet závisí od ostatných riadkov).
     * FOR NO KEY UPDATE nekoliduje s FK kontrolou pri INSERT účtu.
     */
    private function lockEntity(int $entityId): void
    {
        $this->db->query('SELECT id FROM entities WHERE id = :e: FOR NO KEY UPDATE', ['e' => $entityId]);
    }

    /**
     * Účet načítaný v transakcii po zámku subjektu, teda aktuálny stav pre rozhodnutia aj audit.
     *
     * @return array<string, mixed>
     */
    private function lockOrFail(int $entityId, int $accountId): array
    {
        $this->lockEntity($entityId);

        $row = $this->db->query(
            'SELECT * FROM entity_bank_accounts WHERE id = :a: AND entity_id = :e: FOR UPDATE',
            ['a' => $accountId, 'e' => $entityId],
        )->getRowArray();

        if ($row === null) {
            throw new InvalidArgumentException('Bank account ' . $accountId . ' not found for entity ' . $entityId);
        }

        return $this->hydrate($row);
    }

    private function clearDefault(int $entityId, string $now): void
    {
        $this->db->query(
            'UPDATE entity_bank_accounts SET is_default = false, updated_at = :now: WHERE entity_id = :e: AND is_default',
            ['now' => $now, 'e' => $entityId],
        );
    }

    /**
     * Dva prikazy v (volajucou) transakcii: najprv vsetky false, potom vybrany true (RESEARCH Pattern 3).
     * Pri archivacii predvoleneho uctu sa jeho id posiela v $previousId, aby audit nesiel mimo (stary default = archivovany).
     */
    private function applyDefault(int $entityId, int $accountId, string $now, ?int $previousId = null): void
    {
        $old = $previousId ?? $this->currentDefaultId($entityId);

        $this->clearDefault($entityId, $now);
        $this->db->query(
            'UPDATE entity_bank_accounts SET is_default = true, updated_at = :now: WHERE id = :a: AND entity_id = :e: AND archived_at IS NULL',
            ['now' => $now, 'a' => $accountId, 'e' => $entityId],
        );
        $this->audit->record(
            'bank_account',
            $accountId,
            'set_default',
            ['default_account_id' => $old],
            ['default_account_id' => $accountId],
        );
    }

    private function currentDefaultId(int $entityId): ?int
    {
        $row = $this->db->query(
            'SELECT id FROM entity_bank_accounts WHERE entity_id = :e: AND is_default AND archived_at IS NULL',
            ['e' => $entityId],
        )->getRow();

        return $row === null ? null : (int) $row->id;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $row['is_default'] = DbBool::of($row['is_default']);
        $row['currency']   = trim((string) $row['currency']);

        return $row;
    }
}
