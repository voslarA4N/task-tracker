<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Platform\Transaction;

/**
 * Fixtures faktur (Phase 5). Vsetko pod owner() v jednej transakcii, pred commitom
 * SET CONSTRAINTS ALL IMMEDIATE, aby chyba deferred kontroly padla ako vynimka.
 *
 * @mixin PgTestCase
 */
trait InvoiceFixtures
{
    /**
     * @param list<int> $allocationIds
     */
    protected function insertExternalInvoice(int $clientId, string $month, array $allocationIds, ?string $ref = null): int
    {
        $db = $this->owner();

        return Transaction::run($db, function () use ($db, $clientId, $month, $allocationIds, $ref): int {
            $allocs = $this->fixtureAllocations($allocationIds);
            $total  = $this->fixtureSum($allocs);
            $db->query(
                "INSERT INTO invoices (origin, type, external_ref, client_id, billing_month, currency, total, counts_toward_limit, line_count, snapshot, snapshot_schema, created_at)
                 VALUES ('external', 'invoice', :ref:, :client:, :month:, :ccy:, :total:, false, :n:, '{\"schema\":1,\"origin\":\"external\"}', 1, :now:)",
                [
                    'ref'    => $ref,
                    'client' => $clientId,
                    'month'  => $month,
                    'ccy'    => trim((string) $allocs[0]->currency),
                    'total'  => $total,
                    'n'      => count($allocs),
                    'now'    => service('clock')->now()->format('Y-m-d H:i:sP'),
                ],
            );
            $invoiceId = (int) $db->query('SELECT max(id) AS id FROM invoices')->getRow()->id;
            $this->fixtureLines($invoiceId, $allocs, null);

            if ($allocationIds !== []) {
                $db->query('UPDATE billing_allocations SET invoice_id = :inv: WHERE id IN (' . implode(',', array_map('intval', $allocationIds)) . ')', ['inv' => $invoiceId]);
            }

            $db->query('SET CONSTRAINTS ALL IMMEDIATE');

            return $invoiceId;
        });
    }

    /**
     * @param list<int>            $allocationIds
     * @param array<string, mixed> $overrides     number, series_id, total, line_count, vat_code, sha256, without_document, snapshot_schema, with_report
     */
    protected function insertIssuedInvoiceRaw(int $clientId, int $entityId, string $month, array $allocationIds, string $pdfBytes = '%PDF-1.4 test', array $overrides = []): int
    {
        $db = $this->owner();

        return Transaction::run($db, function () use ($db, $clientId, $entityId, $month, $allocationIds, $pdfBytes, $overrides): int {
            $allocs  = $this->fixtureAllocations($allocationIds);
            $total   = (string) ($overrides['total'] ?? $this->fixtureSum($allocs));
            $series  = $overrides['series_id'] ?? $db->query("SELECT id FROM invoice_series WHERE entity_id = :e: AND kind = 'invoice'", ['e' => $entityId])->getRow()->id;
            $version = $db->query('SELECT id, doc_language, vat_regime FROM entity_versions WHERE entity_id = :e: ORDER BY valid_from DESC LIMIT 1', ['e' => $entityId])->getRow();
            $number  = array_key_exists('number', $overrides) ? $overrides['number'] : '202610001';
            $now     = service('clock')->now()->format('Y-m-d H:i:sP');

            $db->query(
                "INSERT INTO invoices (origin, type, entity_id, entity_version_id, series_id, number, client_id, billing_month, issue_date, delivery_date, due_date, turnover_date,
                    currency, total, total_entity_ccy, counts_toward_limit, line_count, doc_language, vat_regime, billing_mode, template_version, snapshot, snapshot_schema, created_at)
                 VALUES ('issued', 'invoice', :entity:, :ver:, :series:, :number:, :client:, :month:, '2026-10-27', '2026-10-27', :due:, '2026-10-27',
                    :ccy:, :total:, :total:, true, :n:, :lang:, :regime:, 'domestic', 'test-1', '{\"schema\":1}', :schema:, :now:)",
                [
                    'entity' => $entityId,
                    'ver'    => $version->id,
                    'series' => $series,
                    'number' => $number,
                    'client' => $clientId,
                    'month'  => $month,
                    'due'    => (string) ($overrides['due_date'] ?? '2026-11-10'),
                    'ccy'    => trim((string) $allocs[0]->currency),
                    'total'  => $total,
                    'n'      => (int) ($overrides['line_count'] ?? count($allocs)),
                    'lang'   => $version->doc_language,
                    'regime' => $version->vat_regime,
                    'now'    => $now,
                    'schema' => (int) ($overrides['snapshot_schema'] ?? 1),
                ],
            );
            $invoiceId = (int) $db->query('SELECT max(id) AS id FROM invoices')->getRow()->id;
            $this->fixtureLines($invoiceId, $allocs, array_key_exists('vat_code', $overrides) ? $overrides['vat_code'] : 'np');

            if (($overrides['without_document'] ?? false) !== true) {
                $db->query(
                    "INSERT INTO invoice_documents (invoice_id, kind, content, byte_size, sha256, template_version, renderer, created_at)
                     VALUES (:inv:, 'invoice', decode(:hex:, 'hex'), :size:, :sha:, 'test-1', 'test', :now:)",
                    [
                        'inv'  => $invoiceId,
                        'hex'  => bin2hex($pdfBytes),
                        'size' => strlen($pdfBytes),
                        'sha'  => $overrides['sha256'] ?? hash('sha256', $pdfBytes),
                        'now'  => $now,
                    ],
                );
            }

            if (($overrides['with_report'] ?? false) === true) {
                $db->query(
                    "INSERT INTO invoice_documents (invoice_id, kind, content, byte_size, sha256, template_version, renderer, created_at)
                     VALUES (:inv:, 'report', decode(:hex:, 'hex'), :size:, :sha:, 'rep-1', 'test', :now:)",
                    ['inv' => $invoiceId, 'hex' => bin2hex($pdfBytes), 'size' => strlen($pdfBytes), 'sha' => hash('sha256', $pdfBytes), 'now' => $now],
                );
            }

            $db->query('SET CONSTRAINTS ALL IMMEDIATE');

            return $invoiceId;
        });
    }

    /**
     * @param list<int> $ids
     *
     * @return list<object>
     */
    private function fixtureAllocations(array $ids): array
    {
        return $this->owner()->query(
            'SELECT a.*, t.title FROM billing_allocations a JOIN tasks t ON t.id = a.task_id WHERE a.id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY a.id',
        )->getResult();
    }

    /**
     * @param list<object> $allocs
     */
    private function fixtureSum(array $allocs): string
    {
        $ids = array_map(static fn (object $a): int => (int) $a->id, $allocs);

        return (string) $this->owner()->query('SELECT coalesce(sum(amount), 0)::numeric(12,2)::text AS s FROM billing_allocations WHERE id IN (' . implode(',', $ids) . ')')->getRow()->s;
    }

    /**
     * @param list<object> $allocs
     */
    private function fixtureLines(int $invoiceId, array $allocs, ?string $vatCode): void
    {
        foreach ($allocs as $i => $a) {
            $this->owner()->query(
                'INSERT INTO invoice_lines (invoice_id, position, source_allocation_id, source_task_id, title, hours, rate, amount, vat_code)
                 VALUES (:inv:, :pos:, :alloc:, :task:, :title:, :hours:, :rate:, :amount:, :vat:)',
                [
                    'inv'    => $invoiceId,
                    'pos'    => $i + 1,
                    'alloc'  => $a->id,
                    'task'   => $a->task_id,
                    'title'  => $a->title,
                    'hours'  => $a->hours,
                    'rate'   => $a->rate,
                    'amount' => $a->amount,
                    'vat'    => $vatCode,
                ],
            );
        }
    }
}
