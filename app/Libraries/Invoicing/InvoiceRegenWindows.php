<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Platform\Clock;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Lehota pregenerovania PDF faktury zmrazena pri vystaveni (WR-03). Neskorsia zmena nastavenia sa tyka len novych faktur.
 */
final class InvoiceRegenWindows
{
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
     * Doplni do riadku faktury kluc regen_until zo zmrazeneho riadku. Bez riadku ostane null,
     * co politika berie ako uzavretu lehotu (window_expired).
     *
     * @param array<string, mixed> $invoice musi obsahovat id
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

        // fail closed: bez zmrazeneho riadku (nemalo by nastat, migracia 000003 doplnila stare doklady) je lehota uzavreta

        return $invoice;
    }
}
