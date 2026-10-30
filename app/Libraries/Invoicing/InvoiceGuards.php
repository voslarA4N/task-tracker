<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Fx\FxQuote;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Spolocne kontroly pre nahlad aj vystavenie (D-01, D-05, D-10): mena, aktivny subjekt, spatny datum.
 */
final class InvoiceGuards
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
     * @param list<array<string, mixed>|array{currency: string}> $parts
     *
     * @throws IssueRejected
     */
    public function partsCurrency(array $parts): string
    {
        if ($parts === []) {
            throw new IssueRejected('no_parts');
        }

        $currencies = [];

        foreach ($parts as $part) {
            $currencies[trim((string) $part['currency'])] = true;
        }

        if (count($currencies) > 1) {
            throw new IssueRejected('mixed_currency', ['currencies' => array_keys($currencies)]);
        }

        return (string) array_key_first($currencies);
    }

    /**
     * @param array<string, mixed> $entity
     *
     * @throws IssueRejected
     */
    public function assertCurrency(array $entity, string $invoiceCurrency): void
    {
        $entityCurrency = trim((string) $entity['currency']);

        if ($entityCurrency !== $invoiceCurrency) {
            throw new IssueRejected('currency_mismatch', ['currency' => $invoiceCurrency, 'entity_currency' => $entityCurrency]);
        }
    }

    /**
     * D-03: mena faktury sa lisi od meny subjektu, kurz musi byt k dispozicii.
     *
     * @param array<string, mixed> $entity
     *
     * @throws IssueRejected
     */
    public function assertFx(array $entity, string $invoiceCurrency, ?FxQuote $fx): void
    {
        $entityCurrency = trim((string) $entity['currency']);

        if ($entityCurrency !== $invoiceCurrency && $fx === null) {
            throw new IssueRejected('fx_missing', ['currency' => $invoiceCurrency, 'entity_currency' => $entityCurrency]);
        }
    }

    /**
     * @param array<string, mixed> $entity
     *
     * @throws IssueRejected
     */
    public function assertEntityActive(array $entity, string $issueDate): void
    {
        $from = (string) $entity['active_from'];
        $to   = $entity['active_to'] === null ? null : (string) $entity['active_to'];

        if ($from > $issueDate || ($to !== null && $to < $issueDate)) {
            throw new IssueRejected('entity_inactive', ['date' => $issueDate]);
        }
    }

    /**
     * @return array{number: string, issue_date: string}|null
     */
    public function lastIssue(int $seriesId): ?array
    {
        $row = $this->db->query(
            "SELECT number, issue_date FROM invoices WHERE series_id = :s: AND origin = 'issued' ORDER BY issue_date DESC, id DESC LIMIT 1",
            ['s' => $seriesId],
        )->getRowArray();

        return $row === null ? null : ['number' => (string) $row['number'], 'issue_date' => (string) $row['issue_date']];
    }

    /**
     * @return array{number: string, issue_date: string}|null posledna faktura, ak je issueDate skorsi
     */
    public function backdate(int $seriesId, string $issueDate): ?array
    {
        $last = $this->lastIssue($seriesId);

        return $last !== null && $issueDate < $last['issue_date'] ? $last : null;
    }
}
