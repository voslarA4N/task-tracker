<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Master\InvalidSeriesFormat;
use App\Libraries\Master\SeriesFormat;
use App\Libraries\Platform\Money;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Nahlad dobropisu (D-03, D-07, D-12, D-17): iba citanie, bez transakcie, zamkov a zapisu. Pouziva rovnaky
 * CreditNoteSnapshotBuilder ako vystavenie, takze preview token z nahladu sedi pri vystaveni.
 */
final class CreditNoteDraftService
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly CreditNoteSnapshotBuilder $builder;
    private readonly InvoiceGuards $guards;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?CreditNoteSnapshotBuilder $builder = null, ?InvoiceGuards $guards = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->builder = $builder ?? new CreditNoteSnapshotBuilder();
        $this->guards  = $guards ?? new InvoiceGuards($this->db);
    }

    /**
     * @return array{original: array<string, mixed>, lines: list<array<string, mixed>>, snapshot: array<string, mixed>, token: string|null, issue_date: string, number_preview: string, paid_amount: string, release_count: int, backdate: array{number: string, issue_date: string}|null, fx: array<string, mixed>|null, sum_check: string, has_fx: bool}
     *
     * @throws CreditNoteRejected not_found, not_creditable, already_credited
     */
    public function draft(int $originalId, ?string $issueDate, string $reason, ?CorrectionType $type): array
    {
        $orig = $this->db->query('SELECT * FROM invoices WHERE id = :id:', ['id' => $originalId])->getRowArray();

        if ($orig === null) {
            throw new CreditNoteRejected('not_found');
        }

        if ($orig['origin'] !== 'issued' || $orig['type'] !== 'invoice') {
            throw new CreditNoteRejected('not_creditable');
        }

        $existing = $this->db->query("SELECT id, number FROM invoices WHERE corrected_invoice_id = :id: AND type = 'credit_note'", ['id' => $originalId])->getRowArray();

        if ($existing !== null) {
            throw new CreditNoteRejected('already_credited', [], (int) $existing['id'], (string) $existing['number']);
        }

        $lines = $this->db->query('SELECT * FROM invoice_lines WHERE invoice_id = :id: ORDER BY position', ['id' => $originalId])->getResultArray();
        $clock = service('clock');
        $date  = $issueDate !== null && $clock->parseDate($issueDate) !== null ? $issueDate : $clock->today();

        $reason   = trim($reason);
        $len      = mb_strlen($reason);
        $valid    = $type !== null && $len >= 1 && $len <= CreditNoteCommand::REASON_MAX && preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $reason) !== 1;
        $snapshot = $this->builder->build($orig, $lines, $date, $valid ? $reason : '-', $type ?? CorrectionType::Other, null);

        $paid  = $this->db->query('SELECT paid_amount FROM invoice_status_v WHERE id = :id:', ['id' => $originalId])->getRowArray();
        $count = (int) $this->db->query('SELECT COUNT(*) AS n FROM billing_allocations WHERE invoice_id = :o:', ['o' => $originalId])->getRow()->n;

        $series   = $this->db->query("SELECT * FROM invoice_series WHERE entity_id = :e: AND kind = 'credit_note'", ['e' => $orig['entity_id']])->getRowArray();
        $backdate = $series === null ? null : $this->guards->backdate((int) $series['id'], $date);

        /** @var array<string, mixed>|null $fx */
        $fx = $snapshot['fx'] ?? null;

        return [
            'original'       => $orig,
            'lines'          => $snapshot['lines'],
            'snapshot'       => $snapshot,
            'token'          => $valid ? CreditNoteSnapshotBuilder::previewToken($snapshot) : null,
            'issue_date'     => $date,
            'number_preview' => $series === null ? '' : $this->numberPreview($series, $date),
            'paid_amount'    => $paid === null ? '0.00' : Money::of((string) $paid['paid_amount'])->toDecimalString(),
            'release_count'  => $count,
            'backdate'       => $backdate,
            'fx'             => $fx,
            'sum_check'      => Money::of((string) $snapshot['totals']['total'])->plus(Money::of((string) $orig['total']))->toDecimalString(),
            'has_fx'         => $orig['fx_rate'] !== null,
        ];
    }

    /**
     * Odkaz na prehlad fakturacie mesiaca povodnej faktury (uvolnene casti, UI-SPEC I).
     */
    public function releaseLink(int $originalId): ?string
    {
        $row = $this->db->query('SELECT client_id, billing_month FROM invoices WHERE id = :id:', ['id' => $originalId])->getRowArray();

        return $row === null ? null : '/invoicing?' . http_build_query(['client' => (int) $row['client_id'], 'month' => trim((string) $row['billing_month'])]);
    }

    /**
     * Dobropis k povodnej fakture s rovnakym request_key (opakovany POST po strate odpovede).
     *
     * @return array{id: int, number: string}|null
     */
    public function replayFor(int $originalId, string $requestKey): ?array
    {
        if (preg_match(self::UUID, $requestKey) !== 1) {
            return null;
        }

        $row = $this->db->query(
            "SELECT id, number FROM invoices WHERE type = 'credit_note' AND corrected_invoice_id = :o: AND request_key = CAST(:k: AS uuid)",
            ['o' => $originalId, 'k' => $requestKey],
        )->getRowArray();

        return $row === null ? null : ['id' => (int) $row['id'], 'number' => (string) $row['number']];
    }

    /**
     * Cislo, ktore by dostal dobropis (pravidlo NumberAllocator::next bez zapisu).
     *
     * @param array<string, mixed> $series
     */
    private function numberPreview(array $series, string $date): string
    {
        try {
            $format = SeriesFormat::parse((string) $series['format']);
        } catch (InvalidSeriesFormat) {
            return '';
        }

        $seriesId = (int) $series['id'];
        $counter  = $this->db->query(
            'SELECT last_value FROM invoice_counters WHERE series_id = :s: AND period_key = :p:',
            ['s' => $seriesId, 'p' => $format->periodKey($date)],
        )->getRowArray();

        if ($counter !== null) {
            $value = (int) $counter['last_value'] + 1;
        } else {
            $hasInvoices = $this->db->query('SELECT 1 FROM invoices WHERE series_id = :s: LIMIT 1', ['s' => $seriesId])->getRowArray() !== null;
            $value       = $hasInvoices ? 1 : (int) $series['initial_number'];
        }

        return $value > $format->maxSequence() ? '' : $format->render($date, $value);
    }
}
