<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Platform\Clock;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

/**
 * Lehota pregenerovania PDF faktury zmrazena pri vystaveni (WR-03). Neskorsia zmena nastavenia sa tyka len novych faktur.
 */
final class InvoiceRegenWindows
{
    /**
     * Lehota, ktora platila pred zmrazovanim (faktury bez riadku).
     */
    public const LEGACY_HOURS = 72;

    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly DocumentSettingsService $settings;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?DocumentSettingsService $settings = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->settings = $settings ?? new DocumentSettingsService($this->db);
    }

    /**
     * Zapise zmrazenu lehotu; volat v transakcii vystavenia, hned po vlozeni faktury.
     */
    public function freeze(int $invoiceId, string $createdAt): void
    {
        $hours = $this->settings->invoiceWindowHours();
        $until = Clock::parseInstant($createdAt)->modify('+' . $hours . ' hours')->format('Y-m-d H:i:sP');

        $this->db->query(
            'INSERT INTO invoice_regen_windows (invoice_id, hours, regen_until, created_at) VALUES (:i:, :h:, :u:, :c:)',
            ['i' => $invoiceId, 'h' => $hours, 'u' => $until, 'c' => $createdAt],
        );
    }

    /**
     * Doplni do riadku faktury kluc regen_until (zmrazeny alebo zdedeny created_at + 72 h).
     * Pri neplatnom created_at ostane null, co politika berie ako uzavretu lehotu.
     *
     * @param array<string, mixed> $invoice musi obsahovat id a created_at
     *
     * @return array<string, mixed>
     */
    public function attach(array $invoice): array
    {
        $invoice['regen_until'] = null;

        $row = $this->db->query('SELECT regen_until FROM invoice_regen_windows WHERE invoice_id = :i:', ['i' => (int) ($invoice['id'] ?? 0)])->getRowArray();

        if ($row !== null) {
            $invoice['regen_until'] = (string) $row['regen_until'];

            return $invoice;
        }

        $createdAt = $invoice['created_at'] ?? null;

        if (is_string($createdAt)) {
            try {
                $invoice['regen_until'] = Clock::parseInstant($createdAt)->modify('+' . self::LEGACY_HOURS . ' hours')->format('Y-m-d H:i:sP');
            } catch (InvalidArgumentException) {
                // fail closed: regen_until zostane null
            }
        }

        return $invoice;
    }
}
