<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\PdfRenderer;
use App\Libraries\Invoicing\RenderedPdf;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\FaultInjectingRenderer;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Throwable;

/**
 * @internal
 */
#[Group('integration')]
final class CreditNoteRollbackTest extends PgTestCase
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
     * @param array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int} $s
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function snapshotCounters(array $s, int $orig): array
    {
        $creditSeries = (int) $this->owner()->query("SELECT id FROM invoice_series WHERE entity_id = :e: AND kind = 'credit_note'", ['e' => $s['entity']])->getRow()->id;
        $counters     = (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_counters WHERE series_id = :s:', ['s' => $creditSeries])->getRow()->c;
        $parts        = (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations WHERE invoice_id = :i:', ['i' => $orig])->getRow()->c;
        $docs         = (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_documents')->getRow()->c;

        return [$counters, $parts, $docs];
    }

    /**
     * @param array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int} $s
     * @param array{0: int, 1: int, 2: int}                                                    $before
     */
    private function assertNothingPersisted(array $s, int $orig, array $before): void
    {
        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS c FROM invoices WHERE type = 'credit_note'")->getRow()->c);
        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS c FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id WHERE i.type = 'credit_note'")->getRow()->c);
        $this->assertSame($before, $this->snapshotCounters($s, $orig));
        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE action LIKE '%credit%'")->getRow()->c);

        foreach ($s['parts'] as $p) {
            $this->assertSame($orig, (int) $this->owner()->query('SELECT invoice_id FROM billing_allocations WHERE id = :i:', ['i' => $p])->getRow()->invoice_id);
        }
    }

    public function testRenderFailureLeavesNothingAndNumberStaysFree(): void
    {
        $s    = $this->issueSetup(['parts' => [['8.00', '2026-10'], ['2.00', '2026-10']]]);
        $orig = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
        $cmd  = $this->creditNoteCommand($orig);
        $b    = $this->snapshotCounters($s, $orig);

        try {
            $this->creditNoteIssuer(new FaultInjectingRenderer())->issue($cmd);
            $this->fail('Expected exception');
        } catch (Throwable $e) {
            $this->assertSame('injected render failure', $e->getMessage());
        }

        $this->assertNothingPersisted($s, $orig, $b);

        $cn = $this->creditNoteIssuer()->issue($this->creditNoteCommand($orig));
        $this->assertMatchesRegularExpression('/^DB-\d{6}001$/', $cn->number);
    }

    public function testBadTemplateVersionRollsBack(): void
    {
        $s        = $this->issueSetup(['parts' => [['8.00', '2026-10']]]);
        $orig     = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
        $cmd      = $this->creditNoteCommand($orig);
        $b        = $this->snapshotCounters($s, $orig);
        $renderer = new class () implements PdfRenderer {
            public function render(array $snapshot): RenderedPdf
            {
                return RenderedPdf::fromBytes('%PDF-1.4 bad', 'wrong-1', 'bad');
            }
        };

        try {
            $this->creditNoteIssuer($renderer)->issue($cmd);
            $this->fail('Expected exception');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        } catch (Throwable $e) {
            $this->addToAssertionCount(1);
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertNothingPersisted($s, $orig, $b);
    }
}
