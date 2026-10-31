<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Platform\Transaction;

/**
 * Fixtures dobropisov (Phase 9). DB testy bez sluzby: dobropis sa vklada cez owner() spojenie
 * ako kopia povodnej faktury so zapornymi riadkami a PDF dokumentom kind = 'invoice'.
 *
 * @mixin PgTestCase
 */
trait CreditNoteFixtures
{
    /**
     * @param array<string, mixed> $overrides stlpce invoices (prepisu hodnoty) a specialne kluce without_document, with_report
     */
    protected function insertRawCreditNote(int $originalId, array $overrides = []): int
    {
        $db = $this->owner();

        return Transaction::run($db, function () use ($db, $originalId, $overrides): int {
            $withoutDocument = (bool) ($overrides['without_document'] ?? false);
            $withReport      = (bool) ($overrides['with_report'] ?? false);
            unset($overrides['without_document'], $overrides['with_report']);

            $orig = $db->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $originalId])->getRowArray();
            $this->assertNotNull($orig);

            $seriesRow = $db->query("SELECT id FROM invoice_series WHERE entity_id = :e: AND kind = 'credit_note'", ['e' => $orig['entity_id']])->getRow();
            $now       = service('clock')->now()->format('Y-m-d H:i:sP');

            $row = $orig;
            unset($row['id']);
            $row['type']                 = 'credit_note';
            $row['origin']               = 'issued';
            $row['series_id']            = $seriesRow?->id ?? $orig['series_id'];
            $row['number']               = 'DB-TEST-' . $originalId;
            $row['corrected_invoice_id'] = $originalId;
            $row['correction_reason']    = 'Test';
            $row['correction_type']      = 'other';
            $row['total']                = bcmul((string) $orig['total'], '-1', 2);
            $row['total_entity_ccy']     = bcmul((string) $orig['total_entity_ccy'], '-1', 2);
            $row['snapshot']             = '{"schema":4}';
            $row['snapshot_schema']      = 4;
            $row['template_version']     = 'cn-1';
            $row['request_key']          = $this->uuid4();
            $row['created_at']           = $now;
            $row                         = array_merge($row, $overrides);

            $cols = array_keys($row);
            $db->query(
                'INSERT INTO invoices (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
                array_values($row),
            );
            $id = (int) $db->query('SELECT max(id) AS id FROM invoices')->getRow()->id;

            $db->query(
                'INSERT INTO invoice_lines (invoice_id, position, source_allocation_id, source_task_id, title, task_ref, portal_url, estimated_hours, hours, rate, amount, vat_code)
                 SELECT :cn:, position, NULL, source_task_id, title, task_ref, portal_url, estimated_hours, -hours, rate, -amount, vat_code
                 FROM invoice_lines WHERE invoice_id = :o: ORDER BY position',
                ['cn' => $id, 'o' => $originalId],
            );

            $pdf = '%PDF-1.4 test';

            foreach (array_filter(['invoice' => ! $withoutDocument, 'report' => $withReport]) as $kind => $_) {
                $db->query(
                    "INSERT INTO invoice_documents (invoice_id, kind, content, byte_size, sha256, template_version, renderer, created_at)
                     VALUES (:inv:, :kind:, decode(:hex:, 'hex'), :size:, :sha:, 'cn-1', 'test', :now:)",
                    ['inv' => $id, 'kind' => $kind, 'hex' => bin2hex($pdf), 'size' => strlen($pdf), 'sha' => hash('sha256', $pdf), 'now' => $now],
                );
            }

            $db->query('SET CONSTRAINTS ALL IMMEDIATE');

            return $id;
        });
    }
}
