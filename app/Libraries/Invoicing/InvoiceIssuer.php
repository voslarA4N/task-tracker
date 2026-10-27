<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\DbBool;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Jediny zapisovatel vlastnych faktur (D-15). Cele vystavenie je jedna transakcia: zamky, vypocet, kopia udajov,
 * cislo, PDF, faktura, riadky, zamknutie casti a audit. Pri akejkolvek chybe sa nezapise nic a cislo ostane volne.
 */
final class InvoiceIssuer
{
    public const REQUEST_KEY_CONSTRAINT = 'invoices_request_key_key';

    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly AuditLogger $audit;
    private readonly RendererRegistry $renderers;
    private readonly SnapshotBuilder $builder;
    private readonly NumberAllocator $numbers;
    private readonly DocumentStore $documents;
    private readonly InvoiceGuards $guards;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(
        ?BaseConnection $db = null,
        ?AuditLogger $audit = null,
        ?RendererRegistry $renderers = null,
        ?SnapshotBuilder $builder = null,
        ?NumberAllocator $numbers = null,
        ?DocumentStore $documents = null,
    ) {
        $this->db        = $db ?? Database::connect();
        $this->audit     = $audit ?? new AuditLogger($this->db);
        $this->renderers = $renderers ?? new RendererRegistry();
        $this->builder   = $builder ?? new SnapshotBuilder($this->db);
        $this->numbers   = $numbers ?? new NumberAllocator($this->db);
        $this->documents = $documents ?? new DocumentStore($this->db);
        $this->guards    = new InvoiceGuards($this->db);
    }

    /**
     * @throws IssueRejected
     */
    public function issue(IssueCommand $cmd): IssuedInvoice
    {
        try {
            return Transaction::run($this->db, fn (): IssuedInvoice => $this->issueInTransaction($cmd));
        } catch (DatabaseException $e) {
            if (str_contains($e->getMessage(), 'is locked')) {
                throw new IssueRejected('parts_changed');
            }

            // WR-08: subezne vystavenie s rovnakym request_key prebehlo skor a commitlo (unique_violation 23505).
            // Po rollbacku je faktura druheho poziadavku uz viditelna, odpovedou je ta ista ako pri opakovani.
            if (self::isUniqueViolation($e, self::REQUEST_KEY_CONSTRAINT)) {
                $this->assertNotIssued($cmd->requestKey);
            }

            throw $e;
        }
    }

    /**
     * Ovladac Postgre v CI4 nevystavuje SQLSTATE, sprava pg_last_error vsak nesie nazov obmedzenia.
     */
    public static function isUniqueViolation(DatabaseException $e, string $constraint): bool
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $message = $t->getMessage();

            if (str_contains($message, 'duplicate key value violates unique constraint "' . $constraint . '"')) {
                return true;
            }
        }

        return false;
    }

    private function issueInTransaction(IssueCommand $cmd): IssuedInvoice
    {
        $ids     = InvoiceParts::idsLiteral($cmd->partIds);
        $created = service('clock')->now()->format('Y-m-d H:i:sP');

        // 1. idempotencia pred zamkami
        $this->assertNotIssued($cmd->requestKey);

        // 2. zamky uloh (poradie: tasks -> clients -> casti -> subjekt -> rad -> citac)
        $taskIds = array_map(
            static fn (array $r): int => (int) $r['task_id'],
            $this->db->query('SELECT DISTINCT task_id FROM billing_allocations WHERE id = ANY(CAST(:ids: AS bigint[]))', ['ids' => $ids])->getResultArray(),
        );
        sort($taskIds);

        if ($taskIds !== []) {
            $this->db->query('SELECT id FROM tasks WHERE id = ANY(CAST(:ids: AS bigint[])) ORDER BY id FOR UPDATE', ['ids' => InvoiceParts::idsLiteral($taskIds)]);
        }

        // 3. klient
        $client = $this->db->query('SELECT * FROM clients WHERE id = :c: FOR SHARE', ['c' => $cmd->clientId])->getRowArray();

        if ($client === null) {
            throw new IssueRejected('not_found');
        }

        // 4. zamknutie casti (len nezamknute, klienta a mesiaca)
        $where = 'a.id = ANY(CAST(:ids: AS bigint[])) AND a.invoice_id IS NULL AND a.billing_month = :m: AND t.client_id = :c:';
        $bind  = ['ids' => $ids, 'm' => $cmd->billingMonth, 'c' => $cmd->clientId];

        // zamok v poradi id (bez deadlockov), poradie riadkov urcuje az druhy select (rovnako ako nahlad)
        $this->db->query(
            "SELECT a.id FROM billing_allocations a JOIN tasks t ON t.id = a.task_id WHERE {$where} ORDER BY a.id FOR UPDATE OF a",
            $bind,
        );

        $rows = $this->db->query(
            "SELECT a.id, a.task_id, a.hours, a.rate, a.amount, a.currency, t.title, t.url, t.hours AS estimated_hours, t.created_at
             FROM billing_allocations a JOIN tasks t ON t.id = a.task_id
             WHERE {$where}
             ORDER BY t.created_at, a.id",
            $bind,
        )->getResultArray();

        if (count($rows) !== count($cmd->partIds)) {
            $this->assertNotIssued($cmd->requestKey);

            $locked = $this->db->query(
                'SELECT i.id, i.number FROM billing_allocations a JOIN invoices i ON i.id = a.invoice_id
                 WHERE a.id = ANY(CAST(:ids: AS bigint[])) ORDER BY i.id LIMIT 1',
                ['ids' => $ids],
            )->getRowArray();

            throw new IssueRejected('parts_changed', [], $locked === null ? null : (int) $locked['id'], $locked === null || $locked['number'] === null ? null : (string) $locked['number']);
        }

        // 5. subjekt
        $entity = $this->db->query('SELECT * FROM entities WHERE id = :e: FOR SHARE', ['e' => $cmd->entityId])->getRowArray();

        if ($entity === null) {
            throw new IssueRejected('not_found');
        }

        // 6. kopia udajov a kontrola nahladu
        $template = ($client['portal_url_template'] ?? null) === null ? null : (string) $client['portal_url_template'];
        $parts    = array_map(static fn (array $r): array => InvoiceParts::toCalculatorInput($r, $template), $rows);

        // kontroly pred vyberom bankoveho uctu (inak by mena skoncila ako no_bank_account)
        $this->guards->assertEntityActive($entity, $cmd->issueDate);
        $this->guards->assertCurrency($entity, $this->guards->partsCurrency($parts));

        $snapshot = $this->builder->build($client, $entity, $parts, $cmd->billingMonth, $cmd->issueDate, $cmd->deliveryDate, $cmd->dueDate, null, $cmd->paymentMethod, $cmd->paymentNote);
        $this->guards->assertCurrency($entity, $snapshot['currency']);

        if (! hash_equals($cmd->previewToken, InvoiceCalculator::previewToken($snapshot))) {
            throw new IssueRejected('preview_stale');
        }

        // 7. rad dokladov (rovnake poradie zamkov ako SeriesService::update)
        $seriesRows = $this->db->query('SELECT * FROM invoice_series WHERE entity_id = :e: ORDER BY id FOR UPDATE', ['e' => $cmd->entityId])->getResultArray();
        $series     = null;

        foreach ($seriesRows as $row) {
            if ($row['kind'] === 'invoice') {
                $series = $row;
            }
        }

        if ($series === null) {
            throw new IssueRejected('not_found');
        }

        $backdate = $this->guards->backdate((int) $series['id'], $cmd->issueDate);

        if ($backdate !== null && ! $cmd->confirmBackdate) {
            throw new IssueRejected('backdate_unconfirmed', ['last_number' => $backdate['number'], 'last_date' => $backdate['issue_date']]);
        }

        // 8. cislo
        $allocated = $this->numbers->next($series, $cmd->issueDate);
        $snapshot  = $this->builder->withNumber($snapshot, $allocated->number);

        // 9. PDF vnutri transakcie
        $pdf = $this->renderers->for((string) $snapshot['template_version'])->render($snapshot);

        if ($pdf->templateVersion !== $snapshot['template_version']) {
            throw new RuntimeException('Renderer returned template ' . $pdf->templateVersion . ', snapshot requires ' . $snapshot['template_version'] . '.');
        }

        // 9b. PDF vykaz v tej istej transakcii (D-05, D-20): zlyhanie renderu vrati cislo aj casti
        try {
            $report = $this->renderers->for((string) $snapshot['report_template_version'])->render($snapshot);
        } catch (DatabaseException $e) {
            throw $e;
        } catch (Throwable $e) {
            log_message('error', 'Report render failed: ' . $e::class . ': ' . $e->getMessage());

            throw new IssueRejected('report_failed');
        }

        if ($report->templateVersion !== $snapshot['report_template_version']) {
            throw new RuntimeException('Report renderer returned template ' . $report->templateVersion . ', snapshot requires ' . $snapshot['report_template_version'] . '.');
        }

        // 10. faktura so strukturovanymi stlpcami zo snapshotu
        $invoiceId = $this->insertInvoice($cmd, $snapshot, $client, $series, $created);

        // 11. riadky a dokument
        foreach ($snapshot['lines'] as $line) {
            $this->insertLine($invoiceId, $line);
        }

        $this->documents->store($invoiceId, 'invoice', $pdf);
        $this->documents->store($invoiceId, 'report', $report);

        // 12. zamknutie casti
        $locked = (int) $this->db->query('SELECT lock_allocations(:inv:, CAST(:ids: AS bigint[])) AS n', ['inv' => $invoiceId, 'ids' => $ids])->getRow()->n;

        if ($locked !== count($cmd->partIds)) {
            throw new RuntimeException('lock_allocations locked ' . $locked . ' parts, expected ' . count($cmd->partIds));
        }

        // 13. audit (bez snapshotu a bajtov)
        $this->audit->record('invoice', $invoiceId, 'issue', null, [
            'number'        => $allocated->number,
            'client_id'     => $cmd->clientId,
            'entity_id'     => $cmd->entityId,
            'billing_month' => $cmd->billingMonth,
            'total'         => $snapshot['totals']['total'],
            'currency'      => $snapshot['currency'],
            'line_count'    => $snapshot['totals']['line_count'],
            'sha256'        => $pdf->sha256,
            'report_sha256' => $report->sha256,
        ]);

        // 14. deferred kontrola uplnosti padne ako vynimka este vnutri transakcie
        $this->db->query('SET CONSTRAINTS ALL IMMEDIATE');

        return new IssuedInvoice($invoiceId, $allocated->number, $snapshot['totals']['total'], $snapshot['currency'], $pdf->sha256);
    }

    private function assertNotIssued(string $requestKey): void
    {
        $existing = $this->db->query('SELECT id, number FROM invoices WHERE request_key = CAST(:k: AS uuid)', ['k' => $requestKey])->getRowArray();

        if ($existing !== null) {
            throw new IssueRejected('already_issued', [], (int) $existing['id'], (string) $existing['number']);
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $client
     * @param array<string, mixed> $series
     */
    private function insertInvoice(IssueCommand $cmd, array $snapshot, array $client, array $series, string $created): int
    {
        return (int) $this->db->query(
            "INSERT INTO invoices (origin, type, request_key, entity_id, entity_version_id, series_id, number, client_id, billing_month,
                issue_date, delivery_date, due_date, turnover_date, currency, total, total_entity_ccy, counts_toward_limit, line_count,
                doc_language, vat_regime, billing_mode, exemption_code, entity_vat_id, client_identifier_type, client_identifier_value, client_vat_id,
                bank_iban, bank_swift, payment_method, payment_note, snapshot, snapshot_schema, template_version, created_at, created_by)
             VALUES ('issued', 'invoice', CAST(:rk: AS uuid), :entity:, :version:, :series:, :number:, :client:, :month:,
                :issue:, :delivery:, :due:, :turnover:, :ccy:, :total:, :total:, CAST(:counts: AS boolean), :n:,
                :lang:, :regime:, :mode:, :exemption:, :entity_vat:, :cit:, :civ:, :cvat:,
                :iban:, :swift:, :pm:, :pn:, CAST(:snapshot: AS jsonb), :schema:, :tpl:, :created:, :by:) RETURNING id",
            [
                'rk'         => $cmd->requestKey,
                'pm'         => $snapshot['payment']['method'],
                'pn'         => $snapshot['payment']['note'],
                'schema'     => (int) $snapshot['schema'],
                'entity'     => $cmd->entityId,
                'version'    => $snapshot['entity']['version_id'],
                'series'     => (int) $series['id'],
                'number'     => $snapshot['number'],
                'client'     => $cmd->clientId,
                'month'      => $cmd->billingMonth,
                'issue'      => $snapshot['issue_date'],
                'delivery'   => $snapshot['delivery_date'],
                'due'        => $snapshot['due_date'],
                'turnover'   => $snapshot['delivery_date'], // datum dodania
                'ccy'        => $snapshot['currency'],
                'total'      => $snapshot['totals']['total'],
                'counts'     => DbBool::toDb(DbBool::of($client['counts_toward_limit'])),
                'n'          => $snapshot['totals']['line_count'],
                'lang'       => $snapshot['doc_language'],
                'regime'     => $snapshot['legal']['vat_regime'],
                'mode'       => $snapshot['legal']['billing_mode'],
                'exemption'  => $snapshot['legal']['exemption_code'],
                'entity_vat' => $snapshot['entity']['vat_id'],
                'cit'        => $snapshot['client']['identifier_type'],
                'civ'        => $snapshot['client']['identifier_value'],
                'cvat'       => $snapshot['client']['vat_id'],
                'iban'       => $snapshot['bank']['iban'],
                'swift'      => $snapshot['bank']['swift'],
                'snapshot'   => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'tpl'        => $snapshot['template_version'],
                'created'    => $created,
                'by'         => $cmd->userId,
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
             VALUES (:inv:, :pos:, :alloc:, :task:, :title:, :ref:, :url:, :est:, :hours:, :rate:, :amount:, :vat:)',
            [
                'inv'    => $invoiceId,
                'pos'    => $line['position'],
                'alloc'  => $line['source_allocation_id'],
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
