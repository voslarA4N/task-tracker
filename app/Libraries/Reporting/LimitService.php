<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\CurrencyRepository;
use App\Libraries\Master\EntityDirectory;
use App\Libraries\Platform\DbBool;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;

/**
 * Zmeny limitov obratu (LIM-01): validacia, transakcia s optimistickym zamkom revision, audit pred/po.
 *
 * @phpstan-import-type LimitRow from LimitSelector
 */
final class LimitService
{
    private const UNIQUE = 'turnover_limits_name_uq';

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
     * @return array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool, revision: int}|null
     */
    public function find(int $id): ?array
    {
        $r = $this->db->query(
            "SELECT id, country, year, name, amount, currency, calc_method, array_to_string(thresholds, ',') AS thresholds_csv, is_seed, revision
             FROM turnover_limits WHERE id = :id:",
            ['id' => $id],
        )->getRowArray();

        return $r === null ? null : $this->map($r);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @throws LimitRejected
     */
    public function create(array $raw): int
    {
        $row = $this->validated($raw);
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        try {
            return Transaction::run($this->db, function () use ($row, $now): int {
                $this->db->query(
                    "INSERT INTO turnover_limits (country, year, name, amount, currency, calc_method, thresholds, is_seed, revision, created_at, updated_at)
                     VALUES (:c:, :y:, :n:, :a:, :cur:, :m:, CAST(string_to_array(:t:, ',') AS integer[]), false, 1, :now:, :now:)",
                    ['c' => $row['country'], 'y' => $row['year'], 'n' => $row['name'], 'a' => $row['amount'], 'cur' => $row['currency'], 'm' => $row['calc_method'], 't' => $row['thresholds_csv'], 'now' => $now],
                );
                $id = (int) $this->db->query('SELECT currval(pg_get_serial_sequence(\'turnover_limits\', \'id\')) AS id')->getRow()->id;

                $this->audit->record('turnover_limit', $id, 'create', null, $this->auditData($row, 1, false));

                return $id;
            });
        } catch (DatabaseException $e) {
            $this->duplicateOrRethrow($e);
        }
    }

    /**
     * @param array<string, mixed> $raw vratane revision
     *
     * @throws LimitRejected
     */
    public function update(int $id, array $raw): void
    {
        $row      = $this->validated($raw);
        $revision = LimitInput::normalize($raw)['revision'];
        $now      = service('clock')->now()->format('Y-m-d H:i:sP');

        try {
            Transaction::run($this->db, function () use ($id, $row, $revision, $now): void {
                $old = $this->lock($id, $revision);

                $this->db->query(
                    "UPDATE turnover_limits SET country = :c:, year = :y:, name = :n:, amount = :a:, currency = :cur:, calc_method = :m:,
                            thresholds = CAST(string_to_array(:t:, ',') AS integer[]), is_seed = false, revision = revision + 1, updated_at = :now:
                     WHERE id = :id:",
                    ['c' => $row['country'], 'y' => $row['year'], 'n' => $row['name'], 'a' => $row['amount'], 'cur' => $row['currency'], 'm' => $row['calc_method'], 't' => $row['thresholds_csv'], 'now' => $now, 'id' => $id],
                );

                $this->audit->recordChange(
                    'turnover_limit',
                    $id,
                    'update',
                    $this->auditData($this->rowOf($old), $old['revision'], $old['is_seed']),
                    $this->auditData($row, $old['revision'] + 1, false),
                );
            });
        } catch (DatabaseException $e) {
            $this->duplicateOrRethrow($e);
        }
    }

    /**
     * @return array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool, revision: int} zmazany riadok
     *
     * @throws LimitRejected
     */
    public function delete(int $id, int $revision): array
    {
        return Transaction::run($this->db, function () use ($id, $revision): array {
            $old = $this->lock($id, (string) $revision);

            $this->db->query('DELETE FROM turnover_limits WHERE id = :id:', ['id' => $id]);
            $this->audit->record('turnover_limit', $id, 'delete', $this->auditData($this->rowOf($old), $old['revision'], $old['is_seed']), null);

            return $old;
        });
    }

    /**
     * Mena aktivneho subjektu krajiny, ak sa lisi od meny limitu (varovanie vo formulari, neblokuje).
     */
    public function currencyWarning(string $country, string $currency, string $today): ?string
    {
        foreach ((new EntityDirectory($this->db))->listAll($today) as $entity) {
            if ($entity['country'] === $country && $entity['status'] === 'active' && $entity['currency'] !== $currency) {
                return $entity['currency'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds_csv: string}
     */
    private function validated(array $raw): array
    {
        $v      = LimitInput::normalize($raw);
        $repo   = new CurrencyRepository($this->db);
        $errors = LimitInput::validate($v, static fn (string $c): bool => $repo->isActive($c));

        if ($errors !== []) {
            throw new LimitRejected('invalid', $errors);
        }

        return LimitInput::toRow($v);
    }

    /**
     * Zamkne riadok (SELECT ... FOR UPDATE) a overi revision; volat iba v transakcii.
     *
     * @return array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool, revision: int}
     */
    private function lock(int $id, string $revision): array
    {
        $r = $this->db->query(
            "SELECT id, country, year, name, amount, currency, calc_method, array_to_string(thresholds, ',') AS thresholds_csv, is_seed, revision
             FROM turnover_limits WHERE id = :id: FOR UPDATE",
            ['id' => $id],
        )->getRowArray();

        if ($r === null) {
            throw new LimitRejected('not_found');
        }

        $old = $this->map($r);

        if ($revision !== (string) $old['revision']) {
            throw new LimitRejected('stale');
        }

        return $old;
    }

    /**
     * @return never
     */
    private function duplicateOrRethrow(DatabaseException $e): void
    {
        if (str_contains($e->getMessage(), self::UNIQUE)) {
            throw new LimitRejected('duplicate', ['name' => 'Limits.errDuplicate']);
        }

        throw $e;
    }

    /**
     * @param array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool, revision: int} $r
     *
     * @return array{country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds_csv: string}
     */
    private function rowOf(array $r): array
    {
        return [
            'country'        => $r['country'],
            'year'           => $r['year'],
            'name'           => $r['name'],
            'amount'         => $r['amount'],
            'currency'       => $r['currency'],
            'calc_method'    => $r['calc_method'],
            'thresholds_csv' => implode(',', $r['thresholds']),
        ];
    }

    /**
     * @param array{country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds_csv: string} $row
     *
     * @return array<string, mixed>
     */
    private function auditData(array $row, int $revision, bool $isSeed): array
    {
        return [
            'country'     => $row['country'],
            'year'        => $row['year'],
            'name'        => $row['name'],
            'amount'      => $row['amount'],
            'currency'    => $row['currency'],
            'calc_method' => $row['calc_method'],
            'thresholds'  => $row['thresholds_csv'],
            'is_seed'     => $isSeed,
            'revision'    => $revision,
        ];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool, revision: int}
     */
    private function map(array $r): array
    {
        return [
            'id'          => (int) $r['id'],
            'country'     => trim((string) $r['country']),
            'year'        => (int) $r['year'],
            'name'        => (string) $r['name'],
            'amount'      => (string) $r['amount'],
            'currency'    => trim((string) $r['currency']),
            'calc_method' => (string) $r['calc_method'],
            'thresholds'  => LimitSelector::parseThresholds((string) $r['thresholds_csv']),
            'is_seed'     => DbBool::of($r['is_seed']),
            'revision'    => (int) $r['revision'],
        ];
    }
}
