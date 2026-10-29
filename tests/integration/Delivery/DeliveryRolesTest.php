<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

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
final class DeliveryRolesTest extends PgTestCase
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

    private function insertSend(): int
    {
        $this->rw()->query(
            "INSERT INTO invoice_sends (invoice_id, kind, sent_at, transport, counts_as_sent, created_at)
             VALUES (:i:, 'invoice', now(), 'smtp', true, now())",
            ['i' => $this->invoice],
        );

        return (int) $this->owner()->query('SELECT max(id) AS id FROM invoice_sends')->getRow()->id;
    }

    public function testRwCanInsertSendsAndVoidsAndReadView(): void
    {
        $id = $this->insertSend();
        $this->rw()->query(
            "INSERT INTO invoice_send_voids (send_id, reason, voided_at) VALUES (:s:, 'omyl', now())",
            ['s' => $id],
        );

        $this->assertSame(1, (int) $this->rw()->query('SELECT count(*) AS c FROM invoice_status_v WHERE id = :i:', ['i' => $this->invoice])->getRow()->c);
        $this->assertSame(1, (int) $this->rw()->query('SELECT count(*) AS c FROM invoice_send_voids')->getRow()->c);
    }

    public function testSendsAreNotUpdatableOrDeletable(): void
    {
        $id = $this->insertSend();

        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE invoice_sends SET note = 'x' WHERE id = :i:", ['i' => $id]));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM invoice_sends WHERE id = :i:', ['i' => $id]));
    }

    public function testVoidsAreNotUpdatableOrDeletable(): void
    {
        $id = $this->insertSend();
        $this->rw()->query("INSERT INTO invoice_send_voids (send_id, reason, voided_at) VALUES (:s:, 'omyl', now())", ['s' => $id]);

        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE invoice_send_voids SET reason = 'x'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM invoice_send_voids'));
    }

    public function testOwnerCannotMutateOrTruncateNonEmptySends(): void
    {
        $id = $this->insertSend();

        foreach (["UPDATE invoice_sends SET note = 'x' WHERE id = {$id}", "DELETE FROM invoice_sends WHERE id = {$id}", 'TRUNCATE invoice_sends CASCADE'] as $sql) {
            try {
                $this->owner()->query($sql);
                $this->fail('Expected immutable violation for: ' . $sql);
            } catch (DatabaseException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
    }
}
