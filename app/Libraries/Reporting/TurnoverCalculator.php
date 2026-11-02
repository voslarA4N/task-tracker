<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use App\Libraries\Master\EntityDirectory;
use App\Libraries\Platform\Money;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Jediny zdroj cisla obratu a cerpania limitov (LIM-02, CUR-04). Cita iba denormalizovane stlpce
 * faktur zapisane pri vystaveni (total_entity_ccy, turnover_date, counts_toward_limit, origin)
 * a pociatocny obrat. Datum "dnes" dodava volajuci.
 */
final class TurnoverCalculator
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly LimitSelector $selector;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?LimitSelector $selector = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->selector = $selector ?? new LimitSelector($this->db);
    }

    /**
     * @return array{total: string, invoices: int, credit_notes: int}
     */
    public function turnover(int $entityId, string $from, string $to): array
    {
        $row = $this->db->query(
            "SELECT COALESCE(SUM(total_entity_ccy), 0) AS total,
                    COUNT(*) FILTER (WHERE type = 'invoice') AS invoices,
                    COUNT(*) FILTER (WHERE type = 'credit_note') AS credit_notes
             FROM invoices
             WHERE entity_id = :e: AND counts_toward_limit AND origin = 'issued'
               AND turnover_date BETWEEN :from: AND :to:",
            ['e' => $entityId, 'from' => $from, 'to' => $to],
        )->getRowArray();

        return [
            'total'        => Money::of((string) ($row['total'] ?? '0'))->toDecimalString(),
            'invoices'     => (int) ($row['invoices'] ?? 0),
            'credit_notes' => (int) ($row['credit_notes'] ?? 0),
        ];
    }

    /**
     * @param list<int> $years
     *
     * @return array{amount: string, years: list<int>}
     */
    public function opening(int $entityId, array $years): array
    {
        if ($years === []) {
            return ['amount' => '0.00', 'years' => []];
        }

        $rows = $this->db->query(
            'SELECT year, amount FROM entity_opening_turnover WHERE entity_id = :e: AND year IN (' . implode(',', array_map('intval', $years)) . ') ORDER BY year',
            ['e' => $entityId],
        )->getResultArray();

        $amounts = [];
        $found   = [];

        foreach ($rows as $r) {
            $amounts[] = Money::of((string) $r['amount']);
            $found[]   = (int) $r['year'];
        }

        return ['amount' => Money::sum(...$amounts)->toDecimalString(), 'years' => $found];
    }

    /**
     * @param array{id: int|string, name_internal: string, country: string, currency: string, active_from: string} $entity
     */
    public function forEntity(array $entity, string $refDate): EntityLimits
    {
        $entityId = (int) $entity['id'];
        $country  = trim((string) $entity['country']);
        $currency = trim((string) $entity['currency']);
        $refYear  = (int) substr($refDate, 0, 4);

        $selected = $this->selector->forCountry($country, $refYear);
        $statuses = [];

        foreach ($selected['rows'] as $row) {
            $statuses[] = $this->status($entityId, $currency, (string) $entity['active_from'], $refDate, $refYear, $selected['usedYear'], $row);
        }

        $ok  = array_values(array_filter($statuses, static fn (LimitStatus $s): bool => $s->error === null));
        $err = array_values(array_filter($statuses, static fn (LimitStatus $s): bool => $s->error !== null));
        usort($ok, static fn (LimitStatus $a, LimitStatus $b): int => LimitMath::compareRatio($b->ratio, $a->ratio));

        return new EntityLimits($entityId, (string) $entity['name_internal'], $country, $currency, $refYear, $selected['usedYear'], $selected['rows'] === [], [...$ok, ...$err]);
    }

    public function forEntityId(int $entityId, string $refDate): ?EntityLimits
    {
        $row = $this->db->query(
            'SELECT id, name_internal, country, currency, active_from FROM entities WHERE id = :e:',
            ['e' => $entityId],
        )->getRowArray();

        return $row === null ? null : $this->forEntity($row, $refDate);
    }

    /**
     * @return list<EntityLimits>
     */
    public function forActiveEntities(string $today): array
    {
        $out = [];

        foreach ((new EntityDirectory($this->db))->listAll($today) as $entity) {
            if ($entity['status'] === 'active') {
                $out[] = $this->forEntity($entity, $today);
            }
        }

        return $out;
    }

    /**
     * @param array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool} $row
     */
    private function status(int $entityId, string $currency, string $activeFrom, string $refDate, int $refYear, ?int $usedYear, array $row): LimitStatus
    {
        $fallback = $usedYear !== null ? $refYear : null;

        if ($row['currency'] !== $currency) {
            return new LimitStatus(
                $row['id'],
                $entityId,
                $row['name'],
                $row['country'],
                $row['year'],
                $refYear,
                $fallback,
                $row['calc_method'],
                $row['thresholds'],
                '',
                '',
                '0.00',
                0,
                0,
                '0.00',
                [],
                '0.00',
                $row['amount'],
                $row['amount'],
                null,
                false,
                '0.0',
                '0',
                '0.00',
                '0.00',
                $row['currency'],
                'normal',
                'currency_mismatch',
                $row['is_seed'],
            );
        }

        $period = PeriodResolver::resolve($row['calc_method'], $refDate, $activeFrom);
        $limit  = $period->proRataDays !== null ? PeriodResolver::proRataLimit($row['amount'], $period->proRataDays) : $row['amount'];
        $doc    = $this->turnover($entityId, $period->from, $period->to);
        $open   = $this->opening($entityId, $period->openingYears);
        $total  = Money::of($doc['total'])->plus(Money::of($open['amount']))->toDecimalString();

        return new LimitStatus(
            $row['id'],
            $entityId,
            $row['name'],
            $row['country'],
            $row['year'],
            $refYear,
            $fallback,
            $row['calc_method'],
            $row['thresholds'],
            $period->from,
            $period->to,
            $doc['total'],
            $doc['invoices'],
            $doc['credit_notes'],
            $open['amount'],
            $open['years'],
            $total,
            $row['amount'],
            $limit,
            $period->proRataDays,
            $period->proRataOutsideFirstYear,
            LimitMath::percent($total, $limit),
            LimitMath::ratio($total, $limit),
            LimitMath::remaining($total, $limit),
            LimitMath::exceededBy($total, $limit),
            $row['currency'],
            LimitMath::level($total, $limit, $row['thresholds']),
            null,
            $row['is_seed'],
        );
    }
}
