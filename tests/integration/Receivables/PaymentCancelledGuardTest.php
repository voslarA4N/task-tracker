<?php

declare(strict_types=1);

namespace Tests\Integration\Receivables;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Receivables\PaymentService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * WR-04, D-15: platba na stornovanu fakturu je zakazana aj priamym INSERT-om a dobropis pocka na zamok uhrad.
 *
 * @internal
 */
#[Group('integration')]
final class PaymentCancelledGuardTest extends PgTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function issuedInvoice(): int
    {
        $s = $this->issueSetup(['parts' => [['40.00', '2026-10'], ['10.00', '2026-10']]]);

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['dueDate' => '2026-10-28']))->id;
    }

    /**
     * @param BaseConnection<mixed, mixed> $db
     */
    private function insertPayment(BaseConnection $db, int $id): void
    {
        $db->query('INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, :d:, :a:, now())', ['i' => $id, 'd' => '2026-10-30', 'a' => '10.00']);
    }

    private function paymentCount(int $id): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM payments WHERE invoice_id = :i:', ['i' => $id])->getRow()->c;
    }

    public function testDirectInsertRejectedAfterCreditNote(): void
    {
        $id = $this->issuedInvoice();
        $this->issueCreditNote($id);

        foreach ([$this->rw(), $this->owner()] as $db) {
            try {
                $this->insertPayment($db, $id);
                $this->fail('Expected DatabaseException');
            } catch (DatabaseException $e) {
                $this->assertStringContainsString(PaymentService::CANCELLED_GUARD, $e->getMessage());
            }
        }

        $this->assertSame(0, $this->paymentCount($id));
    }

    public function testDirectInsertAllowedWithoutCreditNote(): void
    {
        $id = $this->issuedInvoice();
        $this->insertPayment($this->rw(), $id);
        $this->assertSame(1, $this->paymentCount($id));
    }

    public function testCreditNoteIssuerWaitsForPaymentsLock(): void
    {
        $id  = $this->issuedInvoice();
        $cmd = $this->creditNoteCommand($id);

        $this->owner()->query("SELECT pg_advisory_lock(hashtext('payments'), :id:)", ['id' => $id]);
        $this->rw()->query("SET lock_timeout = '200ms'");

        try {
            $this->creditNoteIssuer()->issue($cmd);
            $this->fail('Expected lock timeout');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('lock timeout', $e->getMessage());
        } finally {
            $this->rw()->query('RESET lock_timeout');
            $this->owner()->query("SELECT pg_advisory_unlock(hashtext('payments'), :id:)", ['id' => $id]);
        }

        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS c FROM invoices WHERE type = 'credit_note' AND corrected_invoice_id = :i:", ['i' => $id])->getRow()->c);
    }
}
