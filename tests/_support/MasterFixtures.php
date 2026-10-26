<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\ClientService;
use App\Libraries\Master\EntityService;
use App\Libraries\Platform\Money;
use App\Libraries\Platform\Transaction;

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

        foreach (['audit_log', 'entity_bank_accounts', 'invoice_documents', 'invoice_lines', 'invoices', 'invoice_counters', 'invoice_series', 'entity_versions', 'entities', 'billing_allocations', 'tasks', 'clients'] as $t) {
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

    /**
     * Vytvori ulohu raw SQL pod owner(), vrati id.
     *
     * @param array<string, mixed> $overrides
     */
    protected function createTask(int $clientId, array $overrides = []): int
    {
        $now    = service('clock')->now()->format('Y-m-d H:i:sP');
        $values = $overrides + [
            'title'   => 'Úloha',
            'hours'   => '40.00',
            'rate'    => '27.00',
            'status'  => 'in_progress',
            'done_at' => null,
            'url'     => null,
            'note'    => null,
        ];

        $this->owner()->query(
            'INSERT INTO tasks (client_id, url, title, hours, rate, note, status, done_at, import_key, created_at, updated_at)
             VALUES (:client:, :url:, :title:, :hours:, :rate:, :note:, :status:, :done_at:, :import_key:, :now:, :now:)',
            [
                'client'     => $clientId,
                'url'        => $values['url'],
                'title'      => $values['title'],
                'hours'      => $values['hours'],
                'rate'       => $values['rate'],
                'note'       => $values['note'],
                'status'     => $values['status'],
                'done_at'    => $values['done_at'],
                'import_key' => $values['import_key'] ?? null,
                'now'        => $now,
            ],
        );

        return (int) $this->owner()->query('SELECT max(id) AS id FROM tasks')->getRow()->id;
    }

    /**
     * Vytvori fakturacnu cast raw SQL pod owner(), vrati id.
     *
     * @param array<string, mixed> $overrides
     */
    protected function createAllocation(int $taskId, string $hours, string $month, array $overrides = []): int
    {
        $task = $this->owner()->query(
            'SELECT t.rate, c.currency FROM tasks t JOIN clients c ON c.id = t.client_id WHERE t.id = :id:',
            ['id' => $taskId],
        )->getRow();

        $rate   = (string) ($overrides['rate'] ?? $task->rate);
        $amount = (string) ($overrides['amount'] ?? Money::of($rate)->timesHours($hours)->toDecimalString());
        $now    = service('clock')->now()->format('Y-m-d H:i:sP');

        $this->owner()->query(
            'INSERT INTO billing_allocations (task_id, hours, billing_month, rate, amount, currency, invoice_id, created_at, updated_at)
             VALUES (:task:, :hours:, :month:, :rate:, :amount:, :currency:, :invoice:, :now:, :now:)',
            [
                'task'     => $taskId,
                'hours'    => $hours,
                'month'    => $month,
                'rate'     => $rate,
                'amount'   => $amount,
                'currency' => $overrides['currency'] ?? trim((string) $task->currency),
                'invoice'  => $overrides['invoice_id'] ?? null,
                'now'      => $now,
            ],
        );

        return (int) $this->owner()->query('SELECT max(id) AS id FROM billing_allocations')->getRow()->id;
    }

    /**
     * Zamkne cast skutocnou externou fakturou (D-22, Pitfall 1), vrati id faktury.
     */
    protected function lockAllocation(int $allocationId): int
    {
        $db = $this->owner();
        $a  = $db->query(
            'SELECT a.*, t.client_id, t.title FROM billing_allocations a JOIN tasks t ON t.id = a.task_id WHERE a.id = :id:',
            ['id' => $allocationId],
        )->getRow();


        return Transaction::run($db, function () use ($db, $a, $allocationId): int {
            $db->query(
                "INSERT INTO invoices (origin, type, external_ref, client_id, billing_month, currency, total, counts_toward_limit, line_count, snapshot, snapshot_schema, created_at)
                 VALUES ('external', 'invoice', :ref:, :client:, :month:, :ccy:, :total:, false, 1, '{\"schema\":1,\"origin\":\"external\"}', 1, :now:)",
                [
                    'ref'    => 'fixture-' . $allocationId,
                    'client' => $a->client_id,
                    'month'  => $a->billing_month,
                    'ccy'    => trim((string) $a->currency),
                    'total'  => $a->amount,
                    'now'    => service('clock')->now()->format('Y-m-d H:i:sP'),
                ],
            );
            $invoiceId = (int) $db->query('SELECT max(id) AS id FROM invoices')->getRow()->id;
            $db->query(
                'INSERT INTO invoice_lines (invoice_id, position, source_allocation_id, source_task_id, title, hours, rate, amount)
                 VALUES (:inv:, 1, :alloc:, :task:, :title:, :hours:, :rate:, :amount:)',
                ['inv' => $invoiceId, 'alloc' => $a->id, 'task' => $a->task_id, 'title' => $a->title, 'hours' => $a->hours, 'rate' => $a->rate, 'amount' => $a->amount],
            );
            $db->query('UPDATE billing_allocations SET invoice_id = :inv: WHERE id = :id:', ['inv' => $invoiceId, 'id' => $allocationId]);
            $db->query('SET CONSTRAINTS ALL IMMEDIATE');

            return $invoiceId;
        });
    }
}
