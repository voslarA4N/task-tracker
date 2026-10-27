<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Master\PortalUrlTemplate;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Nezamknute fakturacne casti klienta a mesiaca vo tvare vstupu pre InvoiceCalculator.
 */
final class InvoiceParts
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
     * @return list<array{allocation_id:int, task_id:int, title:string, task_ref:?string, portal_url:?string, estimated_hours:?string, hours:string, rate:string, amount:string, currency:string}>
     */
    public function unlocked(int $clientId, string $month, ?string $portalTemplate): array
    {
        $rows = $this->db->query(
            'SELECT a.id, a.task_id, a.hours, a.rate, a.amount, a.currency, t.title, t.url, t.hours AS estimated_hours
             FROM billing_allocations a JOIN tasks t ON t.id = a.task_id
             WHERE t.client_id = :c: AND a.billing_month = :m: AND a.invoice_id IS NULL
             ORDER BY t.created_at, a.id',
            ['c' => $clientId, 'm' => $month],
        )->getResultArray();

        return array_map(static fn (array $r): array => self::toCalculatorInput($r, $portalTemplate), $rows);
    }

    public function hasLocked(int $clientId, string $month): bool
    {
        return $this->db->query(
            'SELECT 1 FROM billing_allocations a JOIN tasks t ON t.id = a.task_id
             WHERE t.client_id = :c: AND a.billing_month = :m: AND a.invoice_id IS NOT NULL LIMIT 1',
            ['c' => $clientId, 'm' => $month],
        )->getRowArray() !== null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{allocation_id:int, task_id:int, title:string, task_ref:?string, portal_url:?string, estimated_hours:?string, hours:string, rate:string, amount:string, currency:string}
     */
    public static function toCalculatorInput(array $row, ?string $portalTemplate): array
    {
        $url       = $row['url'] === null ? null : (string) $row['url'];
        $portalUrl = null;

        if ($url !== null && preg_match('/^\d{1,20}$/D', $url) === 1) {
            $portalUrl = ($portalTemplate !== null && $portalTemplate !== '') ? PortalUrlTemplate::render($portalTemplate, $url) : null;
        } elseif ($url !== null && preg_match('/^https?:\/\//', $url) === 1) {
            $portalUrl = $url;
        }

        return [
            'allocation_id'   => (int) $row['id'],
            'task_id'         => (int) $row['task_id'],
            'title'           => (string) $row['title'],
            'task_ref'        => $url,
            'portal_url'      => $portalUrl,
            'estimated_hours' => ($row['estimated_hours'] ?? null) === null ? null : (string) $row['estimated_hours'],
            'hours'           => (string) $row['hours'],
            'rate'            => (string) $row['rate'],
            'amount'          => (string) $row['amount'],
            'currency'        => trim((string) $row['currency']),
        ];
    }

    /**
     * @param list<int> $ids
     */
    public static function idsLiteral(array $ids): string
    {
        return '{' . implode(',', array_map('intval', $ids)) . '}';
    }
}
