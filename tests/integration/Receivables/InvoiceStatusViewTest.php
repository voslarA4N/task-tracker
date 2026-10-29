<?php

declare(strict_types=1);

namespace Tests\Integration\Receivables;

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
final class InvoiceStatusViewTest extends PgTestCase
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
        $alloc         = $this->createAllocation($this->createTask($this->client), '8.00', '2026-10', ['rate' => '135.00', 'amount' => '1080.00']);
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
    private function row(?int $id = null): array
    {
        return $this->owner()->query('SELECT * FROM invoice_status_v WHERE id = :i:', ['i' => $id ?? $this->invoice])->getRowArray() ?? [];
    }

    private function pay(string $amount, string $on = '2026-10-28', ?int $invoice = null): int
    {
        $this->owner()->query(
            'INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, :d:, :a:, now())',
            ['i' => $invoice ?? $this->invoice, 'd' => $on, 'a' => $amount],
        );

        return (int) $this->owner()->query('SELECT max(id) AS id FROM payments')->getRow()->id;
    }

    private function send(): void
    {
        $this->owner()->query(
            "INSERT INTO invoice_sends (invoice_id, kind, sent_at, transport, counts_as_sent, created_at) VALUES (:i:, 'invoice', now(), 'smtp', true, now())",
            ['i' => $this->invoice],
        );
    }

    public function testNoPaymentsBalanceEqualsTotal(): void
    {
        $row = $this->row();
        $this->assertSame('1080.00', $row['balance']);
        $this->assertSame('0.00', $row['paid_amount']);
        $this->assertNull($row['last_paid_on']);
        $this->assertSame('issued', $row['status']);

        $this->send();
        $this->assertSame('sent', $this->row()['status']);
    }

    public function testPartialThenFullPaymentAndVoid(): void
    {
        $this->pay('500.00', '2026-10-28');
        $row = $this->row();
        $this->assertSame('partially_paid', $row['status']);
        $this->assertSame('580.00', $row['balance']);

        $second = $this->pay('580.00', '2026-11-02');
        $row    = $this->row();
        $this->assertSame('paid', $row['status']);
        $this->assertSame('0.00', $row['balance']);
        $this->assertSame('1080.00', $row['paid_amount']);
        $this->assertSame('2026-11-02', $row['last_paid_on']);

        $this->owner()->query("INSERT INTO payment_voids (payment_id, reason, voided_at) VALUES (:p:, 'omyl', now())", ['p' => $second]);
        $row = $this->row();
        $this->assertSame('partially_paid', $row['status']);
        $this->assertSame('580.00', $row['balance']);
        $this->assertSame('2026-10-28', $row['last_paid_on']);
    }

    public function testPriorityOverSent(): void
    {
        $this->send();
        $this->pay('100.00');
        $this->assertSame('partially_paid', $this->row()['status']);

        $this->pay('980.00');
        $this->assertSame('paid', $this->row()['status']);
    }

    public function testZeroTotalIsNeverPaid(): void
    {
        $alloc = $this->createAllocation($this->createTask($this->client), '1.00', '2026-10', ['rate' => '0.00', 'amount' => '0.00']);
        $zero  = $this->insertIssuedInvoiceRaw($this->client, $this->entity, '2026-10', [$alloc], '%PDF-1.4 test', ['number' => '202610002']);
        $row   = $this->row($zero);

        $this->assertSame('issued', $row['status']);
        $this->assertSame('0.00', $row['balance']);
    }

    public function testExternalStaysExternal(): void
    {
        $alloc    = $this->createAllocation($this->createTask($this->client), '2.00', '2026-09');
        $external = $this->insertExternalInvoice($this->client, '2026-09', [$alloc], 'EXT-1');
        $row      = $this->row($external);

        $this->assertSame('external', $row['status']);
        $this->assertSame('0.00', $row['paid_amount']);
    }

    public function testChecks(): void
    {
        foreach (['0.00', '-5.00'] as $bad) {
            try {
                $this->pay($bad);
                $this->fail('non-positive amount must fail');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }

        $id = $this->pay('10.00');

        try {
            $this->owner()->query("INSERT INTO payment_voids (payment_id, reason, voided_at) VALUES (:p:, '  ', now())", ['p' => $id]);
            $this->fail('empty reason must fail');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->owner()->query("INSERT INTO payment_voids (payment_id, reason, voided_at) VALUES (:p:, 'a', now())", ['p' => $id]);

        try {
            $this->owner()->query("INSERT INTO payment_voids (payment_id, reason, voided_at) VALUES (:p:, 'b', now())", ['p' => $id]);
            $this->fail('duplicate void must fail');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
    }
}
