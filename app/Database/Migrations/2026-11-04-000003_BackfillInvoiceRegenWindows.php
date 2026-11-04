<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Faza 11.1 (IN-03): kazda vlastna vystavena faktura a dobropis ma zmrazeny riadok lehoty pregenerovania.
 * Doklady vystavene pred 2026-11-04-000002 dostanu lehotu, ktora platila pri vystaveni: created_at + 72 h.
 * Len INSERT (tabulka je insert-only). Doklad bez riadku je odteraz uzavrety (fail closed), kod uz nepozna zalozny vypocet.
 */
final class BackfillInvoiceRegenWindows extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "INSERT INTO invoice_regen_windows (invoice_id, hours, regen_until, created_at)
             SELECT i.id, 72, i.created_at + interval '72 hours', i.created_at
             FROM invoices i
             WHERE i.origin = 'issued'
               AND NOT EXISTS (SELECT 1 FROM invoice_regen_windows w WHERE w.invoice_id = i.id)",
        );
    }

    public function down(): void
    {
        // zmrazene lehoty su nemenne (insert-only), spatny krok je zamerne prazdny
    }
}
