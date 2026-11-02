<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\Money;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

/**
 * Pociatocny obrat subjektu a roka (LIM-02, D-07): suma v mene subjektu, prazdna suma = zmazanie.
 * Meno zo vstupu neprijima ani neuklada.
 *
 * @phpstan-type Opening array{id: int, entity_id: int, year: int, amount: string, note: ?string, revision: int}
 */
final class OpeningTurnoverService
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
     * @return Opening|null
     */
    public function find(int $entityId, int $year): ?array
    {
        $r = $this->db->query(
            'SELECT id, entity_id, year, amount, note, revision FROM entity_opening_turnover WHERE entity_id = :e: AND year = :y:',
            ['e' => $entityId, 'y' => $year],
        )->getRowArray();

        return $r === null ? null : $this->map($r);
    }

    /**
     * @param list<int> $entityIds
     *
     * @return list<Opening>
     */
    public function listForEntities(array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $entityIds));
        $out = [];

        foreach ($this->db->query(
            'SELECT id, entity_id, year, amount, note, revision FROM entity_opening_turnover WHERE entity_id IN (' . $ids . ') ORDER BY entity_id, year DESC',
        )->getResultArray() as $r) {
            $out[] = $this->map($r);
        }

        return $out;
    }

    /**
     * @param array<string, string> $v normalizovane hodnoty (amount uz s bodkou)
     *
     * @return array<string, string> pole => lang kluc
     */
    public static function validate(array $v): array
    {
        $errors = [];
        $entity = $v['entity_id'] ?? '';

        if (preg_match('/^\d{1,9}$/D', $entity) !== 1 || (int) $entity < 1) {
            $errors['entity_id'] = 'Limits.errOpeningEntity';
        }

        $year = $v['year'] ?? '';

        if (preg_match('/^\d{4}$/D', $year) !== 1 || (int) $year < 2000 || (int) $year > 2100) {
            $errors['year'] = 'Limits.errYear';
        }

        $amount = $v['amount'] ?? '';

        if ($amount !== '') {
            try {
                if (Money::of($amount)->compare(Money::zero()) < 0) {
                    $errors['amount'] = 'Limits.errOpeningAmount';
                }
            } catch (InvalidArgumentException) {
                $errors['amount'] = 'Limits.errOpeningAmount';
            }
        }

        if (mb_strlen($v['note'] ?? '') > 255) {
            $errors['note'] = 'Limits.errOpeningNote';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $raw entity_id, year, amount, note, revision
     *
     * @return string created|updated|deleted|noop
     *
     * @throws LimitRejected
     */
    public function save(array $raw): string
    {
        $str = static fn (string $k): string => is_scalar($raw[$k] ?? null) ? trim((string) $raw[$k]) : '';
        $v   = [
            'entity_id' => $str('entity_id'),
            'year'      => $str('year'),
            'amount'    => str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}]+/u', '', $str('amount'))),
            'note'      => $str('note'),
        ];
        $revision = $str('revision');

        $errors = self::validate($v);

        if ($errors !== []) {
            throw new LimitRejected('invalid', $errors);
        }

        $entityId = (int) $v['entity_id'];
        $year     = (int) $v['year'];
        $note     = $v['note'] === '' ? null : $v['note'];
        $amount   = $v['amount'] === '' ? null : Money::of($v['amount'])->toDecimalString();
        $now      = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($entityId, $year, $note, $amount, $revision, $now): string {
            if ($this->db->query('SELECT 1 FROM entities WHERE id = :e:', ['e' => $entityId])->getRowArray() === null) {
                throw new LimitRejected('invalid', ['entity_id' => 'Limits.errOpeningEntity']);
            }

            $r = $this->db->query(
                'SELECT id, entity_id, year, amount, note, revision FROM entity_opening_turnover WHERE entity_id = :e: AND year = :y: FOR UPDATE',
                ['e' => $entityId, 'y' => $year],
            )->getRowArray();

            if ($r === null) {
                if ($amount === null) {
                    return 'noop';
                }

                $this->db->query(
                    'INSERT INTO entity_opening_turnover (entity_id, year, amount, note, revision, created_at, updated_at) VALUES (:e:, :y:, :a:, :n:, 1, :now:, :now:)',
                    ['e' => $entityId, 'y' => $year, 'a' => $amount, 'n' => $note, 'now' => $now],
                );
                $id = (int) $this->db->query('SELECT currval(pg_get_serial_sequence(\'entity_opening_turnover\', \'id\')) AS id')->getRow()->id;
                $this->audit->record('entity_opening_turnover', $id, 'create', null, ['entity_id' => $entityId, 'year' => $year, 'amount' => $amount, 'note' => $note, 'revision' => 1]);

                return 'created';
            }

            $old = $this->map($r);

            if ($revision !== (string) $old['revision']) {
                throw new LimitRejected('stale');
            }

            $before = ['entity_id' => $entityId, 'year' => $year, 'amount' => $old['amount'], 'note' => $old['note'], 'revision' => $old['revision']];

            if ($amount === null) {
                $this->db->query('DELETE FROM entity_opening_turnover WHERE id = :id:', ['id' => $old['id']]);
                $this->audit->record('entity_opening_turnover', $old['id'], 'delete', $before, null);

                return 'deleted';
            }

            $this->db->query(
                'UPDATE entity_opening_turnover SET amount = :a:, note = :n:, revision = revision + 1, updated_at = :now: WHERE id = :id:',
                ['a' => $amount, 'n' => $note, 'now' => $now, 'id' => $old['id']],
            );
            $this->audit->recordChange(
                'entity_opening_turnover',
                $old['id'],
                'update',
                $before,
                ['entity_id' => $entityId, 'year' => $year, 'amount' => $amount, 'note' => $note, 'revision' => $old['revision'] + 1],
            );

            return 'updated';
        });
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return Opening
     */
    private function map(array $r): array
    {
        return [
            'id'        => (int) $r['id'],
            'entity_id' => (int) $r['entity_id'],
            'year'      => (int) $r['year'],
            'amount'    => (string) $r['amount'],
            'note'      => $r['note'] === null ? null : (string) $r['note'],
            'revision'  => (int) $r['revision'],
        ];
    }
}
