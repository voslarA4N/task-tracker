<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Master\InvalidSeriesFormat;
use App\Libraries\Master\SeriesFormat;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Prideluje cislo dokladu bez medzier (D-11, D-12, D-14). Volajuci uz drzi invoice_series FOR UPDATE
 * a je v transakcii vystavenia, takze rollback vrati aj citac. Ziadny databazovy generator poradia.
 */
final class NumberAllocator
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array<string, mixed> $series riadok invoice_series (zamknuty volajucim)
     *
     * @throws IssueRejected
     */
    public function next(array $series, string $issueDate): AllocatedNumber
    {
        try {
            $format = SeriesFormat::parse((string) $series['format']);
        } catch (InvalidSeriesFormat) {
            throw new IssueRejected('invalid_series_format');
        }

        $seriesId = (int) $series['id'];
        $period   = $format->periodKey($issueDate);

        // initial_number plati len pre uplne prvy doklad radu; dalsie obdobia zacinaju od 1 (D-12).
        $hasInvoices = $this->db->query('SELECT 1 FROM invoices WHERE series_id = :s: LIMIT 1', ['s' => $seriesId])->getRowArray() !== null;
        $first       = $hasInvoices ? 1 : (int) $series['initial_number'];

        $value = (int) $this->db->query(
            'INSERT INTO invoice_counters (series_id, period_key, last_value) VALUES (:s:, :p:, :first:)
             ON CONFLICT (series_id, period_key) DO UPDATE SET last_value = invoice_counters.last_value + 1
             RETURNING last_value',
            ['s' => $seriesId, 'p' => $period, 'first' => $first],
        )->getRow()->last_value;

        if ($value > $format->maxSequence()) {
            throw new IssueRejected('sequence_overflow', ['format' => $format->format()]);
        }

        return new AllocatedNumber($format->render($issueDate, $value), $value, $period);
    }
}
