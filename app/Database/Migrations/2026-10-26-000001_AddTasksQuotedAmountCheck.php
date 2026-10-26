<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CR-01 (review fazy 4): nacenena suma ulohy round(hours * rate, 2) sa musi zmestit do numeric(12,2),
 * inak kazde citanie sumy (zoznam, dashboard, detail) skonci chybou. Validacia vstupu to uz odmieta,
 * toto je posledna linia v DB.
 *
 * Obmedzenie sa prida ako NOT VALID (nove a menene riadky sa kontroluju hned) a overi sa iba vtedy,
 * ak ziadny existujuci riadok neporusuje podmienku, aby migracia nezlyhala na starych datach.
 */
final class AddTasksQuotedAmountCheck extends Migration
{
    public function up(): void
    {
        $this->db->query(
            'ALTER TABLE tasks ADD CONSTRAINT tasks_quoted_amount_fits CHECK (round(hours * rate, 2) <= 9999999999.99) NOT VALID',
        );

        $bad = (int) $this->db->query('SELECT count(*) AS n FROM tasks WHERE round(hours * rate, 2) > 9999999999.99')->getRow()->n;

        if ($bad === 0) {
            $this->db->query('ALTER TABLE tasks VALIDATE CONSTRAINT tasks_quoted_amount_fits');
        }
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_quoted_amount_fits');
    }
}
