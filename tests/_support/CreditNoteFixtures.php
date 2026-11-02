<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Invoicing\CreditNoteCommand;
use App\Libraries\Invoicing\CreditNoteIssuer;
use App\Libraries\Invoicing\CreditNoteSnapshotBuilder;
use App\Libraries\Invoicing\IssuedCreditNote;
use App\Libraries\Invoicing\PdfRenderer;
use App\Libraries\Invoicing\RendererRegistry;
use App\Libraries\Platform\Money;
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
            $row['series_id']            = $seriesRow->id ?? $orig['series_id'];
            $row['number']               = 'DB-TEST-' . $originalId;
            $row['corrected_invoice_id'] = $originalId;
            $row['correction_reason']    = 'Test';
            $row['correction_type']      = 'other';
            $row['total']                = '-' . ltrim((string) $orig['total'], '-');
            $row['total_entity_ccy']     = '-' . ltrim((string) $orig['total_entity_ccy'], '-');
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

    protected function creditNoteIssuer(?PdfRenderer $cn = null): CreditNoteIssuer
    {
        return new CreditNoteIssuer($this->rw(), null, new RendererRegistry(['cn-1' => $cn ?? new FakePdfRenderer('cn-1')]));
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    protected function loadOriginal(int $id): array
    {
        $row = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $id])->getRowArray();
        $this->assertNotNull($row);

        return [$row, $this->owner()->query('SELECT * FROM invoice_lines WHERE invoice_id = :i: ORDER BY position', ['i' => $id])->getResultArray()];
    }

    /**
     * @param array<string, mixed> $overrides issueDate, reason, correctionType, previewToken, requestKey, confirmBackdate, userId
     */
    protected function creditNoteCommand(int $originalId, array $overrides = []): CreditNoteCommand
    {
        $issueDate = (string) ($overrides['issueDate'] ?? service('clock')->today());
        $reason    = (string) ($overrides['reason'] ?? 'Chybná sadzba');
        $type      = $overrides['correctionType'] ?? CorrectionType::AmountError;

        if (isset($overrides['previewToken'])) {
            $token = (string) $overrides['previewToken'];
        } else {
            [$orig, $lines] = $this->loadOriginal($originalId);
            $token          = CreditNoteSnapshotBuilder::previewToken((new CreditNoteSnapshotBuilder())->build($orig, $lines, $issueDate, $reason, $type, null));
        }

        return new CreditNoteCommand(
            $originalId,
            $issueDate,
            $reason,
            $type,
            $token,
            (string) ($overrides['requestKey'] ?? $this->uuid4()),
            (bool) ($overrides['confirmBackdate'] ?? false),
            $overrides['userId'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function issueCreditNote(int $originalId, array $overrides = []): IssuedCreditNote
    {
        return $this->creditNoteIssuer()->issue($this->creditNoteCommand($originalId, $overrides));
    }

    protected function assertSumsToZero(int $originalId, int $creditNoteId): void
    {
        foreach (['total', 'total_entity_ccy'] as $col) {
            $rows = $this->owner()->query("SELECT {$col} AS v FROM invoices WHERE id IN (:a:, :b:)", ['a' => $originalId, 'b' => $creditNoteId])->getResultArray();
            $this->assertCount(2, $rows);
            $this->assertSame('0.00', Money::of((string) $rows[0]['v'])->plus(Money::of((string) $rows[1]['v']))->toDecimalString(), $col);
        }
    }
}
