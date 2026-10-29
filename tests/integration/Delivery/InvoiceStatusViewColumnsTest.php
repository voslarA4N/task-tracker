<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

use App\Database\Migrations\CreateInvoiceSends;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class InvoiceStatusViewColumnsTest extends PgTestCase
{
    use InvoiceFixtures;
    use MasterFixtures;

    private int $invoice;
    private int $client;
    private int $entity;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();

        $this->entity  = $this->createEntity('SK');
        $this->client  = $this->createClient();
        $alloc         = $this->createAllocation($this->createTask($this->client), '8.00', '2026-10');
        $this->invoice = $this->insertIssuedInvoiceRaw($this->client, $this->entity, '2026-10', [$alloc]);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        return $this->owner()->query('SELECT * FROM invoice_status_v WHERE id = :i:', ['i' => $id])->getRowArray() ?? [];
    }

    private function send(string $kind, bool $counts, string $at = '2026-10-27 10:00:00+01', ?string $transport = null): int
    {
        $this->owner()->query(
            'INSERT INTO invoice_sends (invoice_id, kind, sent_at, transport, counts_as_sent, created_at) VALUES (:i:, :k:, :a:, :t:, :c:, :a:)',
            ['i' => $this->invoice, 'k' => $kind, 'a' => $at, 't' => $transport ?? ($kind === 'manual' ? 'none' : 'smtp'), 'c' => $counts ? 'true' : 'false'],
        );

        return (int) $this->owner()->query('SELECT max(id) AS id FROM invoice_sends')->getRow()->id;
    }

    public function testOriginalColumnsAreKeptInOrderAndNewOnesAppended(): void
    {
        $cols = $this->owner()->query(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'invoice_status_v' ORDER BY ordinal_position",
        )->getResultArray();
        $map = array_column($cols, 'data_type', 'column_name');

        $this->assertSame(
            ['id', 'origin', 'type', 'number', 'external_ref', 'client_id', 'entity_id', 'billing_month', 'issue_date', 'due_date',
                'currency', 'total', 'counts_toward_limit', 'line_count', 'created_at', 'status', 'sent_at', 'send_count', 'last_reminder_at',
                'paid_amount', 'balance', 'last_paid_on'],
            array_keys($map),
        );
        $this->assertSame('bigint', $map['id']);
        $this->assertSame('numeric', $map['total']);
        $this->assertSame('text', $map['status']);
        $this->assertSame('timestamp with time zone', $map['sent_at']);
        $this->assertSame('integer', $map['send_count']);
        $this->assertSame('timestamp with time zone', $map['last_reminder_at']);
        $this->assertSame('numeric', $map['paid_amount']);
        $this->assertSame('numeric', $map['balance']);
        $this->assertSame('date', $map['last_paid_on']);
    }

    public function testIssuedWithoutSendsAndExternal(): void
    {
        $row = $this->row($this->invoice);
        $this->assertSame('issued', $row['status']);
        $this->assertSame(0, (int) $row['send_count']);
        $this->assertNull($row['sent_at']);

        $alloc    = $this->createAllocation($this->createTask($this->client), '2.00', '2026-09');
        $external = $this->insertExternalInvoice($this->client, '2026-09', [$alloc], 'EXT-1');
        $this->assertSame('external', $this->row($external)['status']);
    }

    public function testCountingSendMakesInvoiceSent(): void
    {
        $this->send('invoice', true);
        $row = $this->row($this->invoice);

        $this->assertSame('sent', $row['status']);
        $this->assertSame(1, (int) $row['send_count']);
        $this->assertNotNull($row['sent_at']);
    }

    public function testNonCountingResendKeepsIssued(): void
    {
        $this->send('resend', false);
        $row = $this->row($this->invoice);

        $this->assertSame('issued', $row['status']);
        $this->assertSame(1, (int) $row['send_count']);
    }

    public function testManualMarkAndVoid(): void
    {
        $id = $this->send('manual', true);
        $this->assertSame('sent', $this->row($this->invoice)['status']);

        $this->owner()->query("INSERT INTO invoice_send_voids (send_id, reason, voided_at) VALUES (:s:, 'omyl', now())", ['s' => $id]);
        $this->assertSame('issued', $this->row($this->invoice)['status']);
    }

    public function testReminderSetsLastReminderOnly(): void
    {
        $this->send('reminder', false, '2026-10-28 09:00:00+01');
        $row = $this->row($this->invoice);

        $this->assertNotNull($row['last_reminder_at']);
        $this->assertSame(0, (int) $row['send_count']);
        $this->assertSame('issued', $row['status']);
    }

    public function testChecks(): void
    {
        try {
            $this->send('manual', true, '2026-10-27 10:00:00+01', 'smtp');
            $this->fail('manual with smtp must fail');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $id = $this->send('invoice', true);

        try {
            $this->owner()->query("INSERT INTO invoice_send_voids (send_id, reason, voided_at) VALUES (:s:, '', now())", ['s' => $id]);
            $this->fail('empty reason must fail');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->owner()->query("INSERT INTO invoice_send_voids (send_id, reason, voided_at) VALUES (:s:, 'a', now())", ['s' => $id]);

        try {
            $this->owner()->query("INSERT INTO invoice_send_voids (send_id, reason, voided_at) VALUES (:s:, 'b', now())", ['s' => $id]);
            $this->fail('duplicate void must fail');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testDownRestoresOriginalViewAndIsReversible(): void
    {
        $db = $this->owner();
        $db->query('BEGIN');

        try {
            require_once APPPATH . 'Database/Migrations/2026-10-29-000002_CreateInvoiceSends.php';
            (new CreateInvoiceSends(Database::forge($db)))->restoreOriginalStatusView();
            $n = (int) $db->query("SELECT count(*) AS c FROM information_schema.columns WHERE table_name = 'invoice_status_v'")->getRow()->c;
            $this->assertSame(16, $n);
        } finally {
            $db->query('ROLLBACK');
        }

        $this->assertSame(22, (int) $db->query("SELECT count(*) AS c FROM information_schema.columns WHERE table_name = 'invoice_status_v'")->getRow()->c);
    }
}
