<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * IN-02: peniaze sú v celom projekte numeric(12,2). clients.hourly_rate vznikol ako numeric(10,2).
 * Zväčšenie presnosti pri rovnakej škále nemení uložené hodnoty ani nevyžaduje prepis tabuľky,
 * stĺpcový grant pre app_rw (CreateClients) ostáva zachovaný.
 */
final class WidenClientsHourlyRate extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE clients ALTER COLUMN hourly_rate TYPE numeric(12,2)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE clients ALTER COLUMN hourly_rate TYPE numeric(10,2)');
    }
}
