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
final class PaymentRolesTest extends PgTestCase
{
    use InvoiceFixtures;
    use MasterFixtures;

    private int $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();

        $entity        = $this->createEntity('SK');
        $client        = $this->createClient();
        $alloc         = $this->createAllocation($this->createTask($client), '8.00', '2026-10');
        $this->invoice = $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc]);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function insertPayment(): int
    {
        $this->rw()->query(
            "INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, '2026-10-27', 10.00, now())",
            ['i' => $this->invoice],
        );

        return (int) $this->owner()->query('SELECT max(id) AS id FROM payments')->getRow()->id;
    }

    public function testRwCanInsertPaymentsAndVoidsAndReadView(): void
    {
        $id = $this->insertPayment();
        $this->rw()->query("INSERT INTO payment_voids (payment_id, reason, voided_at) VALUES (:p:, 'omyl', now())", ['p' => $id]);

        $this->assertSame(1, (int) $this->rw()->query('SELECT count(*) AS c FROM invoice_status_v WHERE id = :i:', ['i' => $this->invoice])->getRow()->c);
        $this->assertSame(1, (int) $this->rw()->query('SELECT count(*) AS c FROM payment_voids')->getRow()->c);
    }

    public function testPaymentsAreNotUpdatableOrDeletable(): void
    {
        $id = $this->insertPayment();

        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE payments SET note = 'x' WHERE id = :i:", ['i' => $id]));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM payments WHERE id = :i:', ['i' => $id]));
    }

    public function testVoidsAreNotUpdatableOrDeletable(): void
    {
        $id = $this->insertPayment();
        $this->rw()->query("INSERT INTO payment_voids (payment_id, reason, voided_at) VALUES (:p:, 'omyl', now())", ['p' => $id]);

        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE payment_voids SET reason = 'x'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM payment_voids'));
    }

    public function testOwnerCannotMutateOrTruncateNonEmptyPayments(): void
    {
        $id = $this->insertPayment();
        $this->rw()->query("INSERT INTO payment_voids (payment_id, reason, voided_at) VALUES (:p:, 'omyl', now())", ['p' => $id]);

        foreach ([
            "UPDATE payments SET note = 'x' WHERE id = {$id}",
            "DELETE FROM payments WHERE id = {$id}",
            'TRUNCATE payments CASCADE',
            "UPDATE payment_voids SET reason = 'x'",
            'TRUNCATE payment_voids',
        ] as $sql) {
            try {
                $this->owner()->query($sql);
                $this->fail('Expected immutable violation for: ' . $sql);
            } catch (DatabaseException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
    }
}
