<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * Jediny zapisovatel novych verzii dokladov. Vstup renderu je vyhradne invoices.snapshot,
 * sablona pochadza z DocumentTemplates. Render je mimo transakcie, pod zamkom iba INSERT + audit.
 */
final class DocumentRegenerator
{
    /**
     * Jediny bigint kluc advisory zamku na faktúru (IN-04): hash namespace + id, bez orezania bigint id na int4.
     */
    public const LOCK_KEY_SQL = "hashtextextended('invoice_documents:' || CAST(:id: AS text), 0)";

    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly AuditLogger $audit;
    private readonly RendererRegistry $renderers;
    private readonly DocumentStore $store;
    private readonly InvoiceRegenWindows $windows;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(
        ?BaseConnection $db = null,
        ?AuditLogger $audit = null,
        ?RendererRegistry $renderers = null,
        ?DocumentStore $store = null,
        ?InvoiceRegenWindows $windows = null,
    ) {
        $this->db        = $db ?? Database::connect();
        $this->audit     = $audit ?? new AuditLogger($this->db);
        $this->renderers = $renderers ?? new RendererRegistry();
        $this->store     = $store ?? new DocumentStore($this->db);
        $this->windows   = $windows ?? new InvoiceRegenWindows($this->db);
    }

    /**
     * @throws RegenerateRejected
     */
    public function regenerate(int $invoiceId, string $kind, int $expectedVersion): RegeneratedDocument
    {
        $row = $this->db->query(
            'SELECT id, origin, type, number, snapshot, snapshot_schema, created_at FROM invoices WHERE id = :id:',
            ['id' => $invoiceId],
        )->getRowArray();

        if ($row === null) {
            throw new RegenerateRejected('not_found');
        }

        $row = $this->windows->attach($row);

        RegeneratePolicy::assertAllowed($row, $kind, service('clock')->now());

        $before = $this->store->meta($invoiceId, $kind);

        if ($before === null || $before['version'] !== $expectedVersion) {
            throw new RegenerateRejected('stale', ['kind' => $kind]);
        }

        $tpl = DocumentTemplates::current($kind, (string) $row['type']);

        try {
            $snapshot = json_decode((string) $row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $pdf      = $this->renderers->for($tpl)->render($snapshot);
        } catch (Throwable $e) {
            log_message('error', 'Regenerate {kind} for invoice {id} failed: {class}: {message}', [
                'kind' => $kind, 'id' => $invoiceId, 'class' => $e::class, 'message' => $e->getMessage(),
            ]);

            throw new RegenerateRejected('render_failed', ['kind' => $kind]);
        }

        if ($pdf->templateVersion !== $tpl) {
            log_message('critical', 'Regenerate {kind} for invoice {id}: renderer returned template {got}, expected {want}', [
                'kind' => $kind, 'id' => $invoiceId, 'got' => $pdf->templateVersion, 'want' => $tpl,
            ]);

            throw new RegenerateRejected('render_failed', ['kind' => $kind]);
        }

        return Transaction::run($this->db, function () use ($invoiceId, $kind, $expectedVersion, $row, $pdf): RegeneratedDocument {
            $this->db->query('SELECT pg_advisory_xact_lock(' . self::LOCK_KEY_SQL . ')', ['id' => $invoiceId]);

            $current = $this->store->meta($invoiceId, $kind);

            if ($current === null || $current['version'] !== $expectedVersion) {
                throw new RegenerateRejected('stale', ['kind' => $kind]);
            }

            RegeneratePolicy::assertAllowed($row, $kind, service('clock')->now());

            $version = $this->store->store($invoiceId, $kind, $pdf);

            $this->audit->record(
                'invoice',
                $invoiceId,
                'regenerate_document',
                ['kind' => $kind, 'version' => $current['version'], 'template_version' => $current['template_version'], 'sha256' => $current['sha256']],
                ['kind' => $kind, 'version' => $version, 'template_version' => $pdf->templateVersion, 'sha256' => $pdf->sha256],
            );

            return new RegeneratedDocument($version, $pdf->templateVersion, $pdf->sha256, $current['version'], $current['template_version']);
        });
    }
}
