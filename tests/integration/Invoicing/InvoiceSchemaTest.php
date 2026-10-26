<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class InvoiceSchemaTest extends PgTestCase
{
    use InvoiceFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function setUpPart(string $hours = '8.00'): array
    {
        $entity = $this->createEntity('SK');
        $client = $this->createClient();
        $task   = $this->createTask($client);
        $alloc  = $this->createAllocation($task, $hours, '2026-10');

        return [$entity, $client, $alloc];
    }

    private function assertDbError(string $regex, callable $fn): void
    {
        try {
            $fn();
        } catch (DatabaseException $e) {
            $this->assertMatchesRegularExpression($regex, $e->getMessage());

            return;
        }

        $this->fail('Expected DatabaseException matching ' . $regex);
    }

    public function testColumnsExist(): void
    {
        $expected = [
            'invoices' => ['origin', 'type', 'request_key', 'external_ref', 'entity_id', 'entity_version_id', 'series_id', 'number', 'client_id', 'billing_month',
                'corrected_invoice_id', 'correction_reason', 'correction_type', 'issue_date', 'delivery_date', 'due_date', 'turnover_date', 'currency', 'total',
                'total_entity_ccy', 'counts_toward_limit', 'line_count', 'fx_rate', 'fx_source', 'fx_date', 'fx_table_no', 'fx_date_basis', 'doc_language',
                'vat_regime', 'billing_mode', 'exemption_code', 'entity_vat_id', 'client_identifier_type', 'client_identifier_value', 'client_vat_id',
                'bank_iban', 'bank_swift', 'payment_method', 'payment_note', 'snapshot', 'snapshot_schema', 'template_version', 'created_at', 'created_by'],
            'invoice_lines'     => ['invoice_id', 'position', 'source_allocation_id', 'source_task_id', 'title', 'task_ref', 'portal_url', 'estimated_hours', 'hours', 'rate', 'amount', 'vat_code'],
            'invoice_documents' => ['invoice_id', 'kind', 'content', 'byte_size', 'sha256', 'template_version', 'renderer', 'created_at'],
            'invoice_counters'  => ['series_id', 'period_key', 'last_value'],
        ];

        foreach ($expected as $table => $columns) {
            $have = array_column($this->owner()->query('SELECT column_name FROM information_schema.columns WHERE table_name = :t:', ['t' => $table])->getResultArray(), 'column_name');

            foreach ($columns as $c) {
                $this->assertContains($c, $have, $table . '.' . $c);
            }
        }

        $default = $this->owner()->query("SELECT column_default FROM information_schema.columns WHERE table_name = 'invoices' AND column_name = 'payment_method'")->getRow()->column_default;
        $this->assertStringContainsString('transfer', (string) $default);
    }

    public function testIssuedWithoutNumberFails(): void
    {
        [$entity, $client, $alloc] = $this->setUpPart();
        $this->assertDbError('/invoices_origin_shape/', fn () => $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc], '%PDF-1.4 x', ['number' => null]));
    }

    public function testExternalShapeRules(): void
    {
        [, $client, $alloc] = $this->setUpPart();
        $now                = '2026-10-27 10:00:00+01';
        $ins                = static fn (string $extra, string $vals, bool $limit = false): string => "INSERT INTO invoices (origin, client_id, billing_month, currency, total, counts_toward_limit, line_count, snapshot, snapshot_schema, created_at{$extra})
            VALUES ('external', {$client}, '2026-10', 'EUR', 216.00, " . ($limit ? 'true' : 'false') . ", 1, '{}', 1, '{$now}'{$vals})";

        $this->assertDbError('/invoices_origin_shape/', fn () => $this->owner()->query($ins(', number', ", '1'")));
        $this->assertDbError('/invoices_origin_shape/', fn () => $this->owner()->query($ins('', '', true)));

        $id = $this->insertExternalInvoice($client, '2026-10', [$alloc], 'ref-1');
        $this->assertGreaterThan(0, $id);
        $row = $this->owner()->query('SELECT number, series_id, counts_toward_limit FROM invoices WHERE id = ?', [$id])->getRow();
        $this->assertNull($row->number);
        $this->assertNull($row->series_id);
    }

    public function testDuplicateSeriesNumberFails(): void
    {
        [$entity, $client, $alloc] = $this->setUpPart();
        $task                      = $this->createTask($client);
        $alloc2                    = $this->createAllocation($task, '2.00', '2026-10');
        $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc]);
        $this->assertDbError('/duplicate key|unique/i', fn () => $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc2]));
    }

    public function testCreditNoteNeedsCorrectedInvoice(): void
    {
        [$entity, $client, $alloc] = $this->setUpPart();
        $this->assertDbError('/check constraint|violates/i', function () use ($client, $entity): void {
            $this->owner()->transException(true)->transBegin();

            try {
                $v = $this->owner()->query('SELECT id FROM entity_versions LIMIT 1')->getRow()->id;
                $s = $this->owner()->query("SELECT id FROM invoice_series WHERE entity_id = {$entity} AND kind = 'invoice'")->getRow()->id;
                $this->owner()->query(
                    "INSERT INTO invoices (origin, type, entity_id, entity_version_id, series_id, number, client_id, billing_month, issue_date, delivery_date, due_date, turnover_date, currency, total, total_entity_ccy, counts_toward_limit, line_count, doc_language, vat_regime, billing_mode, template_version, snapshot, snapshot_schema, created_at)
                     VALUES ('issued', 'credit_note', {$entity}, {$v}, {$s}, 'CN1', {$client}, '2026-10', '2026-10-27', '2026-10-27', '2026-11-10', '2026-10-27', 'EUR', -1.00, -1.00, true, 1, 'sk', 'sk_non_vat', 'domestic', 't', '{}', 1, now())",
                );
            } finally {
                $this->owner()->transRollback();
                $this->owner()->resetTransStatus();
                $this->owner()->transException(false);
            }
        });
    }

    public function testLineAmountMustMatchHoursTimesRate(): void
    {
        [$entity, $client, $alloc] = $this->setUpPart();
        $id                        = $this->insertExternalInvoice($client, '2026-10', [$alloc], 'r');
        $this->assertDbError('/check constraint/i', function () use ($id): void {
            $this->owner()->transException(true)->transBegin();

            try {
                $this->owner()->query("INSERT INTO invoice_lines (invoice_id, position, title, hours, rate, amount) VALUES ({$id}, 9, 'x', 2.00, 27.00, 55.00)");
            } finally {
                $this->owner()->transRollback();
                $this->owner()->resetTransStatus();
                $this->owner()->transException(false);
            }
        });
    }
}
