<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\DbBool;
use App\Libraries\Platform\Money;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use RuntimeException;

/**
 * Jediny zapisovatel dobropisov (Phase 9). Dobropis stornuje celu vystavenu fakturu: v jednej transakcii zapise
 * dobropis, zaporne riadky, PDF, uvolni casti povodnej faktury a zapise audit. Kurz a sumy kopiruje z povodnej faktury.
 */
final class CreditNoteIssuer
{
    public const LOCK_NAMESPACE             = 'credit_note';
    public const ONE_PER_INVOICE_CONSTRAINT = 'invoices_one_credit_note_uq';
    public const NUMBER_CONSTRAINT          = 'invoices_entity_id_number_key';
    public const SERIES_NUMBER_CONSTRAINT   = 'invoices_series_id_number_key';

    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly AuditLogger $audit;
    private readonly RendererRegistry $renderers;
    private readonly NumberAllocator $numbers;
    private readonly DocumentStore $documents;
    private readonly InvoiceGuards $guards;
    private readonly CreditNoteSnapshotBuilder $builder;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(
        ?BaseConnection $db = null,
        ?AuditLogger $audit = null,
        ?RendererRegistry $renderers = null,
        ?NumberAllocator $numbers = null,
        ?DocumentStore $documents = null,
        ?InvoiceGuards $guards = null,
        ?CreditNoteSnapshotBuilder $builder = null,
    ) {
        $this->db        = $db ?? Database::connect();
        $this->audit     = $audit ?? new AuditLogger($this->db);
        $this->renderers = $renderers ?? new RendererRegistry();
        $this->numbers   = $numbers ?? new NumberAllocator($this->db);
        $this->documents = $documents ?? new DocumentStore($this->db);
        $this->guards    = $guards ?? new InvoiceGuards($this->db);
        $this->builder   = $builder ?? new CreditNoteSnapshotBuilder();
    }

    /**
     * @throws CreditNoteRejected
     */
    public function issue(CreditNoteCommand $cmd): IssuedCreditNote
    {
        try {
            return Transaction::run($this->db, fn (): IssuedCreditNote => $this->issueInTransaction($cmd));
        } catch (IssueRejected $e) {
            throw new CreditNoteRejected($e->reason, $e->context);
        } catch (DatabaseException $e) {
            if (InvoiceIssuer::isUniqueViolation($e, InvoiceIssuer::REQUEST_KEY_CONSTRAINT)) {
                $this->assertNotIssued($cmd->requestKey);
            }

            if (InvoiceIssuer::isUniqueViolation($e, self::ONE_PER_INVOICE_CONSTRAINT)) {
                $existing = $this->db->query("SELECT id, number FROM invoices WHERE corrected_invoice_id = :i: AND type = 'credit_note'", ['i' => $cmd->originalId])->getRowArray();

                throw new CreditNoteRejected('already_credited', [], $existing === null ? null : (int) $existing['id'], $existing === null ? null : (string) $existing['number']);
            }

            if (InvoiceIssuer::isUniqueViolation($e, self::NUMBER_CONSTRAINT) || InvoiceIssuer::isUniqueViolation($e, self::SERIES_NUMBER_CONSTRAINT)) {
                throw new CreditNoteRejected('number_collision');
            }

            if (str_contains($e->getMessage(), 'is not creditable')) {
                throw new CreditNoteRejected('not_creditable');
            }

            throw $e;
        }
    }

    private function issueInTransaction(CreditNoteCommand $cmd): IssuedCreditNote
    {
        $created = service('clock')->now()->format('Y-m-d H:i:sP');

        // 1. idempotencia
        $this->assertNotIssued($cmd->requestKey);

        // 2. zamok povodnej faktury (app_rw nema UPDATE na invoices, preto advisory zamok)
        $this->db->query("SELECT pg_advisory_xact_lock(hashtext('credit_note'), :id:)", ['id' => $cmd->originalId]);

        // 3. povodna faktura
        $orig = $this->db->query('SELECT * FROM invoices WHERE id = :id:', ['id' => $cmd->originalId])->getRowArray();

        if ($orig === null) {
            throw new CreditNoteRejected('not_found');
        }

        if ($orig['origin'] !== 'issued' || $orig['type'] !== 'invoice') {
            throw new CreditNoteRejected('not_creditable');
        }

        $existing = $this->db->query("SELECT id, number FROM invoices WHERE corrected_invoice_id = :id: AND type = 'credit_note'", ['id' => $cmd->originalId])->getRowArray();

        if ($existing !== null) {
            throw new CreditNoteRejected('already_credited', [], (int) $existing['id'], (string) $existing['number']);
        }

        // 4. datumy
        if ($cmd->issueDate < (string) $orig['issue_date']) {
            throw new CreditNoteRejected('date_before_original', ['original_date' => (string) $orig['issue_date']]);
        }

        $clock  = service('clock');
        $maxDay = $clock->parseDate($clock->today())?->modify('+' . PreviewInput::ISSUE_MAX_AHEAD_DAYS . ' days')->format('Y-m-d');

        if ($maxDay !== null && $cmd->issueDate > $maxDay) {
            throw new CreditNoteRejected('issue_future', ['max_days' => PreviewInput::ISSUE_MAX_AHEAD_DAYS]);
        }

        // 5. zamok uloh
        $lines   = $this->db->query('SELECT * FROM invoice_lines WHERE invoice_id = :id: ORDER BY position', ['id' => $cmd->originalId])->getResultArray();
        $taskIds = [];

        foreach ($lines as $line) {
            if ($line['source_task_id'] !== null) {
                $taskIds[(int) $line['source_task_id']] = true;
            }
        }

        $taskIds = array_keys($taskIds);
        sort($taskIds);

        if ($taskIds !== []) {
            $this->db->query('SELECT id FROM tasks WHERE id = ANY(CAST(:ids: AS bigint[])) ORDER BY id FOR UPDATE', ['ids' => InvoiceParts::idsLiteral($taskIds)]);
        }

        // 6. rad dobropisov
        $seriesRows = $this->db->query('SELECT * FROM invoice_series WHERE entity_id = :e: ORDER BY id FOR UPDATE', ['e' => $orig['entity_id']])->getResultArray();
        $series     = null;

        foreach ($seriesRows as $row) {
            if ($row['kind'] === 'credit_note') {
                $series = $row;
            }
        }

        if ($series === null) {
            throw new CreditNoteRejected('invalid_series_format');
        }

        $backdate = $this->guards->backdate((int) $series['id'], $cmd->issueDate);

        if ($backdate !== null && ! $cmd->confirmBackdate) {
            throw new CreditNoteRejected('backdate_unconfirmed', ['last_number' => $backdate['number'], 'last_date' => $backdate['issue_date']]);
        }

        // 7. kontrola nahladu
        $snapshot = $this->builder->build($orig, $lines, $cmd->issueDate, $cmd->reason, $cmd->correctionType, null);

        if (! hash_equals($cmd->previewToken, CreditNoteSnapshotBuilder::previewToken($snapshot))) {
            throw new CreditNoteRejected('preview_stale');
        }

        // 8. cislo
        $allocated = $this->numbers->next($series, $cmd->issueDate);
        $snapshot  = $this->builder->build($orig, $lines, $cmd->issueDate, $cmd->reason, $cmd->correctionType, $allocated->number);

        // 9. PDF
        $pdf = $this->renderers->for((string) $snapshot['template_version'])->render($snapshot);

        if ($pdf->templateVersion !== $snapshot['template_version']) {
            throw new RuntimeException('Renderer returned template ' . $pdf->templateVersion . ', snapshot requires ' . $snapshot['template_version'] . '.');
        }

        // 10. pocet casti pred uvolnenim
        $before = (int) $this->db->query('SELECT COUNT(*) AS n FROM billing_allocations WHERE invoice_id = :o:', ['o' => $cmd->originalId])->getRow()->n;

        // 11. dobropis
        $creditNoteId = $this->insertCreditNote($cmd, $orig, $snapshot, $series, $created);
        (new InvoiceRegenWindows($this->db))->freeze($creditNoteId, $created);

        // 12. riadky
        foreach ($snapshot['lines'] as $line) {
            $this->insertLine($creditNoteId, $line);
        }

        // 13. dokument
        $this->documents->store($creditNoteId, 'invoice', $pdf);

        // 14. uvolnenie casti
        $released = (int) $this->db->query('SELECT release_allocations(:cn:) AS n', ['cn' => $creditNoteId])->getRow()->n;

        if ($released !== $before) {
            throw new RuntimeException('released parts mismatch: released ' . $released . ', expected ' . $before);
        }

        // 15. audit
        $after = [
            'number'               => $allocated->number,
            'corrected_invoice_id' => $cmd->originalId,
            'corrected_number'     => (string) $orig['number'],
            'reason'               => $cmd->reason,
            'correction_type'      => $cmd->correctionType->value,
            'total'                => $snapshot['totals']['total'],
            'currency'             => $snapshot['currency'],
            'released_parts'       => $released,
            'sha256'               => $pdf->sha256,
        ];

        if ($orig['fx_rate'] !== null) {
            $after['fx_rate']          = (string) $orig['fx_rate'];
            $after['fx_source']        = (string) $orig['fx_source'];
            $after['total_entity_ccy'] = Money::of((string) $orig['total_entity_ccy'])->negate()->toDecimalString();
        }

        $this->audit->record('invoice', $creditNoteId, 'credit_note_issue', null, $after);

        // 16. deferred kontroly padnu este v transakcii
        $this->db->query('SET CONSTRAINTS ALL IMMEDIATE');

        return new IssuedCreditNote($creditNoteId, $allocated->number, $snapshot['totals']['total'], $snapshot['currency'], $pdf->sha256, $cmd->originalId, $released);
    }

    private function assertNotIssued(string $requestKey): void
    {
        $existing = $this->db->query('SELECT id, number FROM invoices WHERE request_key = CAST(:k: AS uuid)', ['k' => $requestKey])->getRowArray();

        if ($existing !== null) {
            throw new CreditNoteRejected('already_issued', [], (int) $existing['id'], (string) $existing['number']);
        }
    }

    /**
     * @param array<string, mixed> $orig
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $series
     */
    private function insertCreditNote(CreditNoteCommand $cmd, array $orig, array $snapshot, array $series, string $created): int
    {
        return (int) $this->db->query(
            "INSERT INTO invoices (origin, type, request_key, entity_id, entity_version_id, series_id, number, client_id, billing_month,
                issue_date, delivery_date, due_date, turnover_date, currency, total, total_entity_ccy, fx_rate, fx_source, fx_date, fx_table_no, fx_date_basis, counts_toward_limit, line_count,
                doc_language, vat_regime, billing_mode, exemption_code, entity_vat_id, client_identifier_type, client_identifier_value, client_vat_id,
                bank_iban, bank_swift, payment_method, payment_note, corrected_invoice_id, correction_reason, correction_type,
                snapshot, snapshot_schema, template_version, created_at, created_by)
             VALUES ('issued', 'credit_note', CAST(:rk: AS uuid), :entity:, :version:, :series:, :number:, :client:, :month:,
                :issue:, :delivery:, :due:, :turnover:, :ccy:, :total:, :total_entity:, :fx_rate:, :fx_source:, :fx_date:, :fx_table:, :fx_basis:, CAST(:counts: AS boolean), :n:,
                :lang:, :regime:, :mode:, :exemption:, :entity_vat:, :cit:, :civ:, :cvat:,
                :iban:, :swift:, :pm:, :pn:, :orig:, :reason:, :ctype:,
                CAST(:snapshot: AS jsonb), :schema:, :tpl:, :created:, :by:) RETURNING id",
            [
                'rk'           => $cmd->requestKey,
                'entity'       => $orig['entity_id'],
                'version'      => $orig['entity_version_id'],
                'series'       => (int) $series['id'],
                'number'       => $snapshot['number'],
                'client'       => $orig['client_id'],
                'month'        => $orig['billing_month'],
                'issue'        => $cmd->issueDate,
                'delivery'     => $orig['delivery_date'],
                'due'          => $cmd->issueDate,
                'turnover'     => $orig['turnover_date'],
                'ccy'          => $orig['currency'],
                'total'        => $snapshot['totals']['total'],
                'total_entity' => Money::of((string) $orig['total_entity_ccy'])->negate()->toDecimalString(),
                'fx_rate'      => $orig['fx_rate'],
                'fx_source'    => $orig['fx_source'],
                'fx_date'      => $orig['fx_date'],
                'fx_table'     => $orig['fx_table_no'],
                'fx_basis'     => $orig['fx_date_basis'],
                'counts'       => DbBool::toDb(DbBool::of($orig['counts_toward_limit'])),
                'n'            => $snapshot['totals']['line_count'],
                'lang'         => $orig['doc_language'],
                'regime'       => $orig['vat_regime'],
                'mode'         => $orig['billing_mode'],
                'exemption'    => $orig['exemption_code'],
                'entity_vat'   => $orig['entity_vat_id'],
                'cit'          => $orig['client_identifier_type'],
                'civ'          => $orig['client_identifier_value'],
                'cvat'         => $orig['client_vat_id'],
                'iban'         => $orig['bank_iban'],
                'swift'        => $orig['bank_swift'],
                'pm'           => $orig['payment_method'],
                'pn'           => $orig['payment_note'],
                'orig'         => $cmd->originalId,
                'reason'       => $cmd->reason,
                'ctype'        => $cmd->correctionType->value,
                'snapshot'     => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'schema'       => CreditNoteSnapshotBuilder::SCHEMA,
                'tpl'          => CreditNoteSnapshotBuilder::TEMPLATE_VERSION,
                'created'      => $created,
                'by'           => $cmd->userId,
            ],
        )->getRow()->id;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function insertLine(int $invoiceId, array $line): void
    {
        $this->db->query(
            'INSERT INTO invoice_lines (invoice_id, position, source_allocation_id, source_task_id, title, task_ref, portal_url, estimated_hours, hours, rate, amount, vat_code)
             VALUES (:inv:, :pos:, NULL, :task:, :title:, :ref:, :url:, :est:, :hours:, :rate:, :amount:, :vat:)',
            [
                'inv'    => $invoiceId,
                'pos'    => $line['position'],
                'task'   => $line['source_task_id'],
                'title'  => $line['title'],
                'ref'    => $line['task_ref'],
                'url'    => $line['portal_url'],
                'est'    => $line['estimated_hours'],
                'hours'  => $line['hours'],
                'rate'   => $line['rate'],
                'amount' => $line['amount'],
                'vat'    => $line['vat_code'],
            ],
        );
    }
}
