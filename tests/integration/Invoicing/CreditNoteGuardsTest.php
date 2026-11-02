<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\CreditNoteRejected;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Receivables\PaymentService;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class CreditNoteGuardsTest extends PgTestCase
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

    /**
     * @param array<string, mixed> $opts
     *
     * @return array{0: array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int}, 1: int}
     */
    private function issuedInvoice(array $opts = [], string $issueDate = '2026-10-27'): array
    {
        $s  = $this->issueSetup($opts + ['parts' => [['40.00', '2026-10']]]);
        $id = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => $issueDate]))->id;

        return [$s, $id];
    }

    private function creditNoteCount(): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM invoices WHERE type = 'credit_note'")->getRow()->c;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function assertRejected(int $originalId, string $reason, array $overrides = []): CreditNoteRejected
    {
        $before = $this->creditNoteCount();

        try {
            $this->issueCreditNote($originalId, $overrides);
            $this->fail('Expected ' . $reason);
        } catch (CreditNoteRejected $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($before, $this->creditNoteCount());

            return $e;
        }
    }

    public function testNotFound(): void
    {
        $this->assertRejected(999999, 'not_found', ['previewToken' => str_repeat('a', 64)]);
    }

    public function testExternalInvoiceIsNotCreditable(): void
    {
        $s  = $this->issueSetup();
        $id = $this->insertExternalInvoice($s['client'], '2026-10', $s['parts']);

        $this->assertRejected($id, 'not_creditable', ['previewToken' => str_repeat('a', 64)]);
    }

    public function testCreditNoteIsNotCreditable(): void
    {
        [, $orig] = $this->issuedInvoice();
        $cn       = $this->issueCreditNote($orig);

        $this->assertRejected($cn->id, 'not_creditable', ['previewToken' => str_repeat('a', 64)]);
    }

    public function testSecondCreditNoteIsAlreadyCredited(): void
    {
        [, $orig] = $this->issuedInvoice();
        $cn       = $this->issueCreditNote($orig);

        $e = $this->assertRejected($orig, 'already_credited', ['previewToken' => str_repeat('a', 64)]);
        $this->assertSame($cn->id, $e->creditNoteId);
        $this->assertSame($cn->number, $e->creditNoteNumber);
    }

    public function testDateBeforeOriginalRejected(): void
    {
        [, $orig] = $this->issuedInvoice();

        $this->assertRejected($orig, 'date_before_original', ['issueDate' => '2026-10-26', 'previewToken' => str_repeat('a', 64)]);
    }

    public function testFutureDateLimit(): void
    {
        [, $orig] = $this->issuedInvoice();

        $this->assertRejected($orig, 'issue_future', ['issueDate' => '2026-11-08', 'previewToken' => str_repeat('a', 64)]);

        $cn = $this->issueCreditNote($orig, ['issueDate' => '2026-11-07']);
        $this->assertMatchesRegularExpression('/^DB-202611\d{3}$/', $cn->number);
    }

    public function testBackdateNeedsConfirmation(): void
    {
        $s    = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.00', '2026-10']]]);
        $a    = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]], ['issueDate' => '2026-10-20']))->id;
        $b    = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]], ['issueDate' => '2026-10-21']))->id;
        $late = $this->issueCreditNote($b, ['issueDate' => '2026-10-30']);

        $e = $this->assertRejected($a, 'backdate_unconfirmed', ['issueDate' => '2026-10-25']);
        $this->assertSame($late->number, $e->context['last_number']);

        $cn = $this->issueCreditNote($a, ['issueDate' => '2026-10-25', 'confirmBackdate' => true]);
        $this->assertSame(2, $this->creditNoteCount());
        $this->assertNotSame($late->number, $cn->number);
    }

    public function testEndedEntityStillGetsCreditNote(): void
    {
        [$s, $orig] = $this->issuedInvoice();
        $this->owner()->query("UPDATE entities SET active_to = '2026-10-28' WHERE id = :e:", ['e' => $s['entity']]);

        $cn = $this->issueCreditNote($orig);
        $this->assertSumsToZero($orig, $cn->id);
    }

    public function testPartiallyPaidInvoiceGetsCreditNote(): void
    {
        [, $orig] = $this->issuedInvoice();
        (new PaymentService($this->rw()))->record($orig, '100.00', '2026-10-30', null, $this->uuid4(), null);

        $cn = $this->issueCreditNote($orig);
        $this->assertSumsToZero($orig, $cn->id);
    }

    public function testNumberCollisionRollsBackCounter(): void
    {
        [$s, $orig] = $this->issuedInvoice();
        $this->owner()->query("UPDATE invoice_series SET format = 'RRRRMM###' WHERE entity_id = :e: AND kind = 'credit_note'", ['e' => $s['entity']]);

        $this->assertRejected($orig, 'number_collision');
        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS c FROM invoice_counters c JOIN invoice_series s ON s.id = c.series_id WHERE s.kind = 'credit_note' AND s.entity_id = :e:", ['e' => $s['entity']])->getRow()->c);
        $this->assertSame($s['parts'][0], (int) $this->owner()->query('SELECT a.id FROM billing_allocations a WHERE a.id = :a: AND a.invoice_id = :i:', ['a' => $s['parts'][0], 'i' => $orig])->getRow()->id);
    }
}
