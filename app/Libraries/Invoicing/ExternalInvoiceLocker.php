<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\Hours;
use App\Libraries\Platform\Money;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use RuntimeException;

/**
 * Zamkne historicky vyfakturovane importovane casti na externu fakturu (D-22, WR-07): bez cisla z radu, bez PDF,
 * counts_toward_limit = false. Rovnake poradie zamkov a rovnaky lock_allocations() ako InvoiceIssuer.
 */
final class ExternalInvoiceLocker
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
     * Nic nezapisuje.
     *
     * @return array{parts: list<array{id: int, task_id: int, title: string, hours: string, rate: string, amount: string, currency: string}>, count: int, hours: string, amount: string, currencies: list<string>}
     */
    public function plan(int $clientId, string $month): array
    {
        return $this->summarize($this->selectParts($clientId, $month, false));
    }

    /**
     * @throws DomainException nothing_to_lock, expectation_mismatch, mixed_currency
     */
    public function lock(int $clientId, string $month, ?string $ref, int $expectParts, string $expectHours, string $expectAmount): int
    {
        return Transaction::run($this->db, function () use ($clientId, $month, $ref, $expectParts, $expectHours, $expectAmount): int {
            // poradie zamkov: tasks -> clients -> casti
            $taskIds = array_map(
                static fn (array $r): int => (int) $r['id'],
                $this->db->query(
                    'SELECT id FROM tasks WHERE client_id = :c: AND import_key IS NOT NULL ORDER BY id FOR UPDATE',
                    ['c' => $clientId],
                )->getResultArray(),
            );
            $this->db->query('SELECT id FROM clients WHERE id = :c: FOR SHARE', ['c' => $clientId]);

            $plan = $this->summarize($taskIds === [] ? [] : $this->selectParts($clientId, $month, true));

            if ($plan['count'] === 0) {
                throw new DomainException('nothing_to_lock');
            }

            if (
                $plan['count'] !== $expectParts
                || Hours::compare($plan['hours'], Hours::of($expectHours)) !== 0
                || ! Money::of($plan['amount'])->equals(Money::of($expectAmount))
            ) {
                throw new DomainException(
                    'expectation_mismatch: skutočnosť ' . $plan['count'] . ' častí, ' . $plan['hours'] . ' h, ' . $plan['amount']
                    . '; očakávané ' . $expectParts . ' častí, ' . $expectHours . ' h, ' . $expectAmount,
                );
            }

            if (count($plan['currencies']) !== 1) {
                throw new DomainException('mixed_currency');
            }

            $currency = $plan['currencies'][0];
            $now      = service('clock')->now()->format('Y-m-d H:i:sP');
            $lines    = [];

            foreach ($plan['parts'] as $i => $p) {
                $lines[] = [
                    'position'             => $i + 1,
                    'source_allocation_id' => $p['id'],
                    'source_task_id'       => $p['task_id'],
                    'title'                => $p['title'],
                    'hours'                => $p['hours'],
                    'rate'                 => $p['rate'],
                    'amount'               => $p['amount'],
                ];
            }

            $snapshot = [
                'schema'        => 1,
                'origin'        => 'external',
                'external_ref'  => $ref,
                'billing_month' => $month,
                'currency'      => $currency,
                'lines'         => $lines,
                'totals'        => ['total' => $plan['amount'], 'hours' => $plan['hours'], 'line_count' => (string) $plan['count']],
            ];

            $invoiceId = (int) $this->db->query(
                "INSERT INTO invoices (origin, type, external_ref, client_id, billing_month, currency, total, counts_toward_limit, line_count, payment_method, snapshot, snapshot_schema, created_at, created_by)
                 VALUES ('external', 'invoice', :ref:, :client:, :month:, :ccy:, :total:, false, :n:, 'transfer', CAST(:snapshot: AS jsonb), 1, :now:, NULL) RETURNING id",
                [
                    'ref'      => $ref,
                    'client'   => $clientId,
                    'month'    => $month,
                    'ccy'      => $currency,
                    'total'    => $plan['amount'],
                    'n'        => $plan['count'],
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'now'      => $now,
                ],
            )->getRow()->id;

            foreach ($lines as $l) {
                $this->db->query(
                    'INSERT INTO invoice_lines (invoice_id, position, source_allocation_id, source_task_id, title, task_ref, hours, rate, amount, vat_code)
                     VALUES (:inv:, :pos:, :alloc:, :task:, :title:, NULL, :hours:, :rate:, :amount:, NULL)',
                    [
                        'inv'    => $invoiceId,
                        'pos'    => $l['position'],
                        'alloc'  => $l['source_allocation_id'],
                        'task'   => $l['source_task_id'],
                        'title'  => $l['title'],
                        'hours'  => $l['hours'],
                        'rate'   => $l['rate'],
                        'amount' => $l['amount'],
                    ],
                );
            }

            $ids    = '{' . implode(',', array_map(static fn (array $p): int => $p['id'], $plan['parts'])) . '}';
            $locked = (int) $this->db->query('SELECT lock_allocations(:inv:, CAST(:ids: AS bigint[])) AS n', ['inv' => $invoiceId, 'ids' => $ids])->getRow()->n;

            if ($locked !== $plan['count']) {
                throw new RuntimeException('lock_allocations locked ' . $locked . ' parts, expected ' . $plan['count']);
            }

            $this->audit->record('invoice', $invoiceId, 'external_lock', null, [
                'client_id'     => $clientId,
                'billing_month' => $month,
                'line_count'    => $plan['count'],
                'total'         => $plan['amount'],
                'currency'      => $currency,
                'external_ref'  => $ref,
            ]);

            $this->db->query('SET CONSTRAINTS ALL IMMEDIATE');

            return $invoiceId;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectParts(int $clientId, string $month, bool $forUpdate): array
    {
        return $this->db->query(
            'SELECT a.id, a.task_id, t.title, a.hours, a.rate, a.amount, a.currency
             FROM billing_allocations a JOIN tasks t ON t.id = a.task_id
             WHERE t.client_id = :c: AND a.billing_month = :m: AND a.invoice_id IS NULL AND t.import_key IS NOT NULL
             ORDER BY t.created_at, a.id' . ($forUpdate ? ' FOR UPDATE OF a' : ''),
            ['c' => $clientId, 'm' => $month],
        )->getResultArray();
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{parts: list<array{id: int, task_id: int, title: string, hours: string, rate: string, amount: string, currency: string}>, count: int, hours: string, amount: string, currencies: list<string>}
     */
    private function summarize(array $rows): array
    {
        $parts      = [];
        $hours      = '0.00';
        $amounts    = [];
        $currencies = [];

        foreach ($rows as $r) {
            $part = [
                'id'       => (int) $r['id'],
                'task_id'  => (int) $r['task_id'],
                'title'    => (string) $r['title'],
                'hours'    => (string) $r['hours'],
                'rate'     => (string) $r['rate'],
                'amount'   => (string) $r['amount'],
                'currency' => trim((string) $r['currency']),
            ];
            $parts[]      = $part;
            $hours        = Hours::plus($hours, $part['hours']);
            $amounts[]    = Money::of($part['amount']);
            $currencies[] = $part['currency'];
        }

        return [
            'parts'      => $parts,
            'count'      => count($parts),
            'hours'      => $hours,
            'amount'     => Money::sum(...$amounts)->toDecimalString(),
            'currencies' => array_values(array_unique($currencies)),
        ];
    }
}
