<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Pre triedy odvodene od PgTestCase. Vyprazdni master tabulky Phase 3.
 * NIKDY nesmie obsahovat seed tabulky z migracii (pravne profily a texty).
 *
 * @mixin PgTestCase
 */
trait MasterFixtures
{
    protected function resetMasterTables(): void
    {
        $existing = [];

        foreach (['audit_log', 'entity_bank_accounts', 'invoice_series', 'entity_versions', 'entities', 'clients'] as $t) {
            $row = $this->owner()->query('SELECT to_regclass(:t:) AS r', ['t' => $t])->getRow();

            if ($row !== null && $row->r !== null) {
                $existing[] = $t;
            }
        }

        if ($existing !== []) {
            $this->owner()->query('TRUNCATE ' . implode(', ', $existing) . ' RESTART IDENTITY CASCADE');
        }
    }
}
