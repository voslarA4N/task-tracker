<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\ClientService;
use App\Libraries\Master\EntityService;

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

    /**
     * Vytvori subjekt cez EntityService (pod app_rw), vrati id.
     *
     * @param array<string, string|null> $overrides prepisuje zakladne aj verzovane polia
     */
    protected function createEntity(string $country = 'SK', array $overrides = []): int
    {
        $pl = $country === 'PL';

        $base = [
            'name_internal' => $pl ? 'Moja firma PL' : 'Moja firma SK',
            'country'       => $country,
            'currency'      => $pl ? 'PLN' : 'EUR',
            'active_from'   => '2026-01-01',
            'active_to'     => null,
        ];
        $version = [
            'trade_name'    => $pl ? 'Jan Kowalski' : 'Jan Novak',
            'address_line1' => 'Hlavna 1',
            'address_line2' => null,
            'postal_code'   => $pl ? '00-001' : '81101',
            'city'          => $pl ? 'Warszawa' : 'Bratislava',
            'company_id'    => $pl ? '5260250274' : '12345678',
            'regon'         => $pl ? '123456785' : null,
            'tax_id'        => null,
            'vat_id'        => null,
            'registry_note' => null,
            'email'         => 'firma@example.test',
            'phone'         => null,
            'doc_language'  => $pl ? 'pl' : 'sk',
            'vat_regime'    => $pl ? 'pl_exempt_113_1' : 'sk_non_vat',
            'due_days'      => '14',
        ];

        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $base)) {
                $base[$key] = $value;
            } else {
                $version[$key] = $value;
            }
        }

        return (new EntityService($this->rw(), new AuditLogger($this->rw())))->create($base, $version);
    }

    /**
     * Vytvori klienta cez ClientService (pod app_rw), vrati id.
     *
     * @param array<string, mixed> $overrides
     */
    protected function createClient(array $overrides = []): int
    {
        $values = $overrides + [
            'name'                => 'All4Net',
            'address_line1'       => 'Hlavna 1',
            'address_line2'       => null,
            'postal_code'         => '81101',
            'city'                => 'Bratislava',
            'country'             => 'SK',
            'identifier_type'     => 'local_id',
            'identifier_value'    => '12345678',
            'tax_id'              => null,
            'vat_id'              => null,
            'invoice_email'       => 'fakturacia@all4net.example',
            'cc_emails'           => [],
            'hourly_rate'         => '27.00',
            'currency'            => 'EUR',
            'doc_language'        => null,
            'due_days'            => null,
            'billing_mode'        => 'domestic',
            'counts_toward_limit' => true,
        ];

        return (new ClientService($this->rw(), new AuditLogger($this->rw())))->create($values);
    }
}
