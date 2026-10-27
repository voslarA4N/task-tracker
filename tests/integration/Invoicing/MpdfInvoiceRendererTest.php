<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\MpdfInvoiceRenderer;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\SnapshotSample;

/**
 * @internal
 */
#[Group('integration')]
final class MpdfInvoiceRendererTest extends CIUnitTestCase
{
    public function testRendersPdfWithHash(): void
    {
        $pdf = (new MpdfInvoiceRenderer())->render(SnapshotSample::issued());

        $this->assertStringStartsWith('%PDF-', $pdf->bytes);
        $this->assertSame(hash('sha256', $pdf->bytes), $pdf->sha256);
        $this->assertSame('inv-1', $pdf->templateVersion);
        $this->assertStringStartsWith('mpdf 8.', $pdf->renderer);
    }

    public function testTextContent(): void
    {
        $text = $this->text(SnapshotSample::issued());

        foreach ([
            '202610001', 'Variabilný symbol', '26. 10. 2026', 'Tomáš Voslař – All4Net', 'All4Net s.r.o.',
            'Úloha – łńěťžô ČŠŽ', '1 080,00 €', '229,50 €', '1 309,50 €', 'SK3112000000198742637541',
            'Nie som platiteľ DPH.', 'np',
        ] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function testPolishLabels(): void
    {
        $labels = ['language' => 'pl'];

        foreach (array_keys(require APPPATH . 'Language/pl/InvoicePdf.php') as $k) {
            $labels[$k] = lang('InvoicePdf.' . $k, [], 'pl');
        }

        $text = $this->text(SnapshotSample::issued(['doc_language' => 'pl', 'labels' => $labels]));

        $this->assertStringContainsString('Faktura', $text);
        $this->assertStringContainsString('Sprzedawca', $text);
        $this->assertStringContainsString('26.10.2026', $text);
    }

    public function testHtmlInTitleIsEscaped(): void
    {
        $s                      = SnapshotSample::issued();
        $s['lines'][0]['title'] = '<img src="http://example.com/x.png"><b>X</b>';

        $text = $this->text($s);

        $this->assertStringContainsString('<img src="http://example.com/x.png"><b>X</b>', $text);
    }

    public function testNullNumberRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MpdfInvoiceRenderer())->render(SnapshotSample::issued(['number' => null]));
    }

    public function testDatesComeFromSnapshot(): void
    {
        $text = $this->text(SnapshotSample::issued(['issue_date' => '2026-09-30']));

        $this->assertStringContainsString('30. 9. 2026', $text);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function text(array $snapshot): string
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext is not available');
        }

        $pdf  = (new MpdfInvoiceRenderer())->render($snapshot);
        $file = tempnam(sys_get_temp_dir(), 'inv');
        file_put_contents($file, $pdf->bytes);
        $out = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' -');
        unlink($file);

        return (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $out);
    }
}
