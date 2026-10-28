<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Invoicing\PdfRenderer;
use App\Libraries\Invoicing\Qr\Lzma;
use App\Libraries\Invoicing\Qr\PayBySquare;
use App\Libraries\Invoicing\Qr\QrPaymentBuilder;
use App\Libraries\Invoicing\Qr\QrPaymentFailed;
use App\Libraries\Invoicing\RenderedPdf;
use App\Libraries\Invoicing\RendererRegistry;
use App\Libraries\Invoicing\SnapshotBuilder;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakePdfRenderer;
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
final class IssueRollbackTest extends PgTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
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
     * @param array<string, mixed> $s
     */
    private function assertNothingPersisted(array $s): void
    {
        foreach (['invoices', 'invoice_lines', 'invoice_documents', 'invoice_counters'] as $table) {
            $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM ' . $table)->getRow()->c, $table);
        }

        $this->assertNull($this->owner()->query('SELECT invoice_id FROM billing_allocations WHERE id = :id:', ['id' => $s['parts'][0]])->getRow()->invoice_id);
        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'invoice'")->getRow()->c);
    }

    public function testRenderFailureAfterNumberAllocationLeavesNothing(): void
    {
        $s = $this->issueSetup();

        try {
            $this->issuer(new FaultInjectingRenderer())->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
            $this->fail('Expected exception');
        } catch (Throwable $e) {
            $this->assertSame('injected render failure', $e->getMessage());
        }

        $this->assertNothingPersisted($s);

        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
        $this->assertSame('202610001', $issued->number);
    }

    public function testReportRenderFailureAfterInvoiceRenderLeavesNothing(): void
    {
        $s = $this->issueSetup();

        try {
            $this->issuer(null, new FaultInjectingRenderer())->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
            $this->fail('Expected IssueRejected');
        } catch (IssueRejected $e) {
            $this->assertSame('report_failed', $e->reason);
        }

        $this->assertNothingPersisted($s);
        $this->assertSame('202610001', $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->number);
    }

    public function testBadDocumentHashRollsBackInvoiceAndLines(): void
    {
        $s        = $this->issueSetup();
        $renderer = new class () implements PdfRenderer {
            public function render(array $snapshot): RenderedPdf
            {
                $pdf = RenderedPdf::fromBytes('%PDF-1.4 bad', 'inv-2', 'bad');
                $bad = unserialize(str_replace($pdf->sha256, hash('sha256', 'something else'), serialize($pdf)));

                if (! $bad instanceof RenderedPdf) {
                    throw new LogicException('unserialize failed');
                }

                return $bad;
            }
        };

        try {
            $this->issuer($renderer)->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
            $this->fail('Expected exception');
        } catch (Throwable $e) {
            $this->assertStringContainsString('invoice_documents', $e->getMessage());
        }

        $this->assertNothingPersisted($s);
        $this->assertSame('202610001', $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->number);
    }

    public function testQrFailureRollsBackAndFreesNumber(): void
    {
        $s       = $this->issueSetup();
        $builder = $this->failingQrBuilder();
        $issuer  = new InvoiceIssuer($this->rw(), null, new RendererRegistry(['inv-2' => new FakePdfRenderer('inv-2'), 'rep-1' => new FakePdfRenderer('rep-1')]), $builder);

        try {
            $issuer->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
            $this->fail('Expected IssueRejected');
        } catch (IssueRejected $e) {
            $this->assertSame('qr_failed', $e->reason);
        }

        $this->assertNothingPersisted($s);
        $this->assertSame('202610001', $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->number);
    }

    /**
     * @return SnapshotBuilder builder, ktoreho LZMA vzdy zlyha
     */
    private function failingQrBuilder(): SnapshotBuilder
    {
        $lzma = new class () implements Lzma {
            public function compress(string $raw): string
            {
                throw new QrPaymentFailed('boom');
            }
        };

        return new SnapshotBuilder($this->rw(), null, null, null, new QrPaymentBuilder(new PayBySquare($lzma)));
    }
}
