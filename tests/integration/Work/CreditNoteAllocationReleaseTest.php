<?php

declare(strict_types=1);

namespace Tests\Integration\Work;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
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
final class CreditNoteAllocationReleaseTest extends PgTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    /** @var array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int} */
    private array $s;
    private int $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();

        $this->s       = $this->issueSetup(['parts' => [['40.00', '2026-10'], ['10.00', '2026-10']]]);
        $this->invoice = $this->issuer()->issue($this->issueCommand($this->s['client'], '2026-10', $this->s['entity'], $this->s['parts']))->id;
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
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

    public function testReleaseAllocationsUnlocksPartsOfCorrectedInvoice(): void
    {
        $before = $this->owner()->query('SELECT * FROM billing_allocations WHERE id IN ? ORDER BY id', [$this->s['parts']])->getResultArray();
        $cn     = $this->insertRawCreditNote($this->invoice);

        $n = (int) $this->rw()->query('SELECT release_allocations(:c:) AS n', ['c' => $cn])->getRow()->n;
        $this->assertSame(2, $n);

        $after = $this->owner()->query('SELECT * FROM billing_allocations WHERE id IN ? ORDER BY id', [$this->s['parts']])->getResultArray();

        foreach ($after as $i => $row) {
            $this->assertNull($row['invoice_id']);
            $expected               = $before[$i];
            $expected['invoice_id'] = null;
            $this->assertSame($expected, $row);
        }
    }

    public function testReleaseOnInvoiceOrUnknownIdDoesNothing(): void
    {
        $this->insertRawCreditNote($this->invoice);

        $this->assertSame(0, (int) $this->rw()->query('SELECT release_allocations(:c:) AS n', ['c' => $this->invoice])->getRow()->n);
        $this->assertSame(0, (int) $this->rw()->query('SELECT release_allocations(:c:) AS n', ['c' => 999999])->getRow()->n);
        $this->assertSame(2, (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations WHERE invoice_id = :i:', ['i' => $this->invoice])->getRow()->c);
    }

    public function testRwCannotUpdateInvoiceIdDirectly(): void
    {
        $this->insertRawCreditNote($this->invoice);
        $part = $this->s['parts'][0];

        $this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE billing_allocations SET invoice_id = NULL WHERE id = ?', [$part]));
    }

    public function testOwnerCannotUnlockWithoutCreditNote(): void
    {
        $part = $this->s['parts'][0];

        $this->assertDbError('/is locked/', fn () => $this->owner()->query('UPDATE billing_allocations SET invoice_id = NULL WHERE id = ?', [$part]));
    }

    public function testOwnerCannotChangeOtherColumnsEvenWithCreditNote(): void
    {
        $this->insertRawCreditNote($this->invoice);
        $part = $this->s['parts'][0];

        $this->assertDbError('/is locked/', fn () => $this->owner()->query('UPDATE billing_allocations SET invoice_id = NULL, hours = 1.00, amount = 27.00 WHERE id = ?', [$part]));
        $this->assertDbError('/is locked/', fn () => $this->owner()->query('DELETE FROM billing_allocations WHERE id = ?', [$part]));
    }
}
