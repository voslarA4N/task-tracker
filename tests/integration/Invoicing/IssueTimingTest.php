<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Invoicing\MpdfInvoiceInv2Renderer;
use App\Libraries\Invoicing\MpdfReportRenderer;
use App\Libraries\Invoicing\PdfRenderer;
use App\Libraries\Invoicing\RenderedPdf;
use App\Libraries\Invoicing\RendererRegistry;
use App\Libraries\Platform\FrozenClock;
use ArrayObject;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * Meranie vystavenia s realnymi PDF (D-22, NFR-01). Skupina performance je mimo predvolenej `make test`,
 * spusta sa explicitne: vendor/bin/phpunit --group performance.
 *
 * @internal
 */
#[Group('integration')]
#[Group('performance')]
final class IssueTimingTest extends PgTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private const LIMIT_MS     = 2000;
    private const LIMIT_MEMORY = 134_217_728;

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
     * @param ArrayObject<string, int> $ms vystup: namerany cas renderu v ms podla sablony
     */
    private function timed(PdfRenderer $inner, string $name, ArrayObject $ms): PdfRenderer
    {
        return new class ($inner, $name, $ms) implements PdfRenderer {
            /**
             * @param ArrayObject<string, int> $ms
             */
            public function __construct(private readonly PdfRenderer $inner, private readonly string $name, private ArrayObject $ms)
            {
            }

            public function render(array $snapshot): RenderedPdf
            {
                $start                 = hrtime(true); // @phpstan-ignore app.forbiddenTime (meranie, nie biznis cas)
                $pdf                   = $this->inner->render($snapshot);
                $this->ms[$this->name] = intdiv((int) (hrtime(true) - $start), 1_000_000); // @phpstan-ignore app.forbiddenTime

                return $pdf;
            }
        };
    }

    public function testIssueWithBothRealPdfsUnderLimits(): void
    {
        $specs = [];

        for ($i = 0; $i < 20; $i++) {
            $specs[] = ['2.00', '2026-10', ['title' => 'Úloha číslo ' . $i . ' – Zażółć gęślą jaźń ľščťžýáíé']];
        }

        $s = $this->issueSetup(['parts' => $specs]);
        /** @var ArrayObject<string, int> $ms */
        $ms = new ArrayObject();

        $issuer = new InvoiceIssuer($this->rw(), null, new RendererRegistry([
            'inv-2' => $this->timed(new MpdfInvoiceInv2Renderer(), 'inv-2', $ms),
            'rep-1' => $this->timed(new MpdfReportRenderer(), 'rep-1', $ms),
        ]));

        $cmd   = $this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']);
        $start = hrtime(true); // @phpstan-ignore app.forbiddenTime
        $issuer->issue($cmd);
        $totalMs = intdiv((int) (hrtime(true) - $start), 1_000_000); // @phpstan-ignore app.forbiddenTime
        $peak    = memory_get_peak_usage(true);

        /** @var array<string, int> $ms */
        $ms = $ms->getArrayCopy();
        fwrite(STDERR, "\n[issue-timing] inv-2 {$ms['inv-2']} ms, rep-1 {$ms['rep-1']} ms, total issue {$totalMs} ms, peak " . intdiv($peak, 1_048_576) . " MB\n");

        $this->assertArrayHasKey('inv-2', $ms);
        $this->assertArrayHasKey('rep-1', $ms);
        $this->assertLessThan(self::LIMIT_MS, $ms['inv-2']);
        $this->assertLessThan(self::LIMIT_MS, $ms['rep-1']);
        $this->assertLessThan(self::LIMIT_MEMORY, $peak);
    }
}
