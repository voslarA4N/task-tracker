<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\MpdfInvoiceInv2Renderer;
use App\Libraries\Invoicing\MpdfReportRenderer;
use App\Libraries\Invoicing\PdfFormat;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PdfText;
use Tests\Support\PgTestCase;

/**
 * REP-02: sucet hodin a sum vo vykaze = vo fakture = v snapshote = v invoice_lines.
 *
 * @internal
 */
#[Group('integration')]
final class ReportTotalsMatchTest extends PgTestCase
{
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

    public function testTotalsAgreeAcrossInvoiceReportSnapshotAndLines(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.50', '2026-10'], ['3.00', '2026-10', ['hours' => '3.00']]]]);

        $issued = $this->issuer(new MpdfInvoiceInv2Renderer(), new MpdfReportRenderer())
            ->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        $row      = $this->owner()->query('SELECT snapshot, total, doc_language, currency FROM invoices WHERE id = :i:', ['i' => $issued->id])->getRowArray();
        $snapshot = json_decode((string) $row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $sum      = $this->owner()->query('SELECT sum(amount)::text AS a, sum(hours)::text AS h FROM invoice_lines WHERE invoice_id = :i:', ['i' => $issued->id])->getRow();
        $lang     = (string) $row['doc_language'];
        $amount   = PdfFormat::amount((string) $row['total'], (string) $row['currency'], $lang);
        $hours    = PdfFormat::hours((string) $snapshot['totals']['hours'], $lang);

        $this->assertSame((string) $row['total'], $snapshot['totals']['total']);
        $this->assertSame((string) $row['total'], (string) $sum->a);
        $this->assertSame($issued->total, (string) $row['total']);
        $this->assertTrue($this->owner()->query('SELECT CAST(:a: AS numeric) = CAST(:b: AS numeric) AS eq', ['a' => (string) $sum->h, 'b' => (string) $snapshot['totals']['hours']])->getRow()->eq === 't');

        $docs = new DocumentStore($this->rw());

        foreach (['invoice', 'report'] as $kind) {
            $doc = $docs->read($issued->id, $kind);
            $this->assertNotNull($doc);
            $text = PdfText::extract($doc->bytes);

            if ($text === null) {
                $this->markTestSkipped('pdftotext is not available');
            }

            $norm = static fn (string $v): string => (string) preg_replace('/[\\s\\x{00A0}\\x{202F}]+/u', ' ', $v);
            $this->assertStringContainsString($norm($amount), $norm($text), $kind . ' total');

            if ($kind === 'report') {
                $this->assertStringContainsString($norm($hours), $norm($text), 'report hours total');
            }
        }
    }
}
