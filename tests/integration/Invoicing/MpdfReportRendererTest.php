<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\MpdfReportRenderer;
use App\Libraries\Invoicing\PdfFormat;
use App\Libraries\Invoicing\ReportRows;
use App\Libraries\Invoicing\Samples\SampleSnapshots;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PdfText;

/**
 * @internal
 */
#[Group('integration')]
final class MpdfReportRendererTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $snapshot
     */
    private function text(array $snapshot): string
    {
        $pdf  = (new MpdfReportRenderer())->render($snapshot);
        $text = PdfText::extract($pdf->bytes);

        if ($text === null) {
            $this->markTestSkipped('pdftotext is not available');
        }

        return (string) preg_replace('/[\\s\\x{00A0}\\x{202F}]+/u', ' ', $text);
    }

    private function norm(string $s): string
    {
        return (string) preg_replace('/[\\s\\x{00A0}\\x{202F}]+/u', ' ', $s);
    }

    public function testRendersPdf(): void
    {
        $pdf = (new MpdfReportRenderer())->render(SampleSnapshots::invoice('sk'));

        $this->assertStringStartsWith('%PDF-', $pdf->bytes);
        $this->assertSame('rep-1', $pdf->templateVersion);
    }

    public function testSlovakContent(): void
    {
        $snap = SampleSnapshots::invoice('sk');
        $text = $this->text($snap);

        $this->assertStringContainsString('Výkaz práce', $text);
        $this->assertStringContainsString('202610001', $text);
        $this->assertStringContainsString('október 2026', $text);
        $this->assertStringContainsString('TM-1', $text);
        $this->assertStringContainsString($this->norm($snap['report']['rows'][0]['billed']), $text);
        $this->assertStringContainsString($this->norm(PdfFormat::amount('1309.50', 'EUR', 'sk')), $text);
        $this->assertStringContainsString('Nie som platiteľ DPH.', $text);
    }

    public function testPolishContent(): void
    {
        $text = $this->text(SampleSnapshots::invoice('pl'));

        $this->assertStringContainsString('Wykaz pracy', $text);
        $this->assertStringContainsString('godz.', $text);
    }

    public function testUnsafeUrlAndHtmlTitleAreText(): void
    {
        $snap                           = SampleSnapshots::invoice('sk');
        $snap['lines'][0]['portal_url'] = 'javascript:x';
        $snap['lines'][0]['title']      = '<img src=http://x>';
        $snap['report']                 = array_replace($snap['report'], ReportRows::build($snap['lines'], $snap['labels']['report'], 'sk'));
        $pdf                            = (new MpdfReportRenderer())->render($snap);

        $this->assertNull($snap['report']['rows'][0]['portal_url']);
        $this->assertStringNotContainsString('javascript:', $pdf->bytes);
        $text = PdfText::extract($pdf->bytes);

        if ($text === null) {
            $this->markTestSkipped('pdftotext is not available');
        }

        $this->assertStringContainsString('<img src=http://x>', $text);
    }

    public function testRateColumnOnlyWhenRatesDiffer(): void
    {
        $uniform = SampleSnapshots::invoice('sk');
        $this->assertSame('27.00', $uniform['report']['uniform_rate']);
        $this->assertStringContainsString('Sadzba: ', $this->text($uniform));

        $mixed                     = SampleSnapshots::invoice('sk');
        $mixed['lines'][1]['rate'] = '30.00';
        $mixed['report']           = array_replace($mixed['report'], ReportRows::build($mixed['lines'], $mixed['labels']['report'], 'sk'));
        $this->assertNull($mixed['report']['uniform_rate']);
        $text = $this->text($mixed);
        $this->assertStringNotContainsString('Sadzba: ', $text);
        $this->assertStringContainsString('Sadzba', $text);
    }

    public function testMissingNumberThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MpdfReportRenderer())->render(SampleSnapshots::invoice('sk', null, ['number' => null]));
    }
}
