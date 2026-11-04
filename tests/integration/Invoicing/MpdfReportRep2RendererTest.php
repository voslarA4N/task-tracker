<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\MpdfReportRep2Renderer;
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
final class MpdfReportRep2RendererTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $snapshot
     */
    private function text(array $snapshot): string
    {
        $pdf  = (new MpdfReportRep2Renderer())->render($snapshot);
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

    /**
     * @return list<string>
     */
    private function uris(string $bytes): array
    {
        preg_match_all('~/URI\s*\((.*?)\)~s', $bytes, $m);

        return array_map(static fn (string $u): string => str_replace('\\/', '/', $u), $m[1]);
    }

    public function testRendersPdf(): void
    {
        $pdf = (new MpdfReportRep2Renderer())->render(SampleSnapshots::invoice('sk'));

        $this->assertStringStartsWith('%PDF-', $pdf->bytes);
        $this->assertSame('rep-2', $pdf->templateVersion);
    }

    public function testLinkPointsDirectlyToExternalUrl(): void
    {
        $snap                           = SampleSnapshots::invoice('sk');
        $url                            = 'https://taskman.all4net.sk/?section=management&project=443&todo=81504';
        $snap['lines'][0]['portal_url'] = $url;
        $snap['report']                 = array_replace($snap['report'], ReportRows::build($snap['lines'], $snap['labels']['report'], 'sk'));

        $uris = $this->uris((new MpdfReportRep2Renderer())->render($snap)->bytes);

        $this->assertContains($url, $uris);
        $this->assertContains('https://taskman.example/2', $uris);

        foreach ($uris as $uri) {
            $this->assertStringNotContainsString('&#', $uri);
            $this->assertMatchesRegularExpression('~^https://taskman\.~', $uri);
        }
    }

    public function testTitleAndLinkShareOneColumn(): void
    {
        $snap = SampleSnapshots::invoice('sk');
        $text = $this->text($snap);

        $this->assertStringNotContainsString('Portál', $text);
        $this->assertStringContainsString('Úloha', $text);
        $title = $this->norm($snap['lines'][0]['title']);
        $this->assertLessThan((int) mb_strpos($text, 'TM-1'), (int) mb_strpos($text, $title));
    }

    public function testSlovakContent(): void
    {
        $snap = SampleSnapshots::invoice('sk');
        $text = $this->text($snap);

        $this->assertStringContainsString('Výkaz práce', $text);
        $this->assertStringContainsString('202610001', $text);
        $this->assertStringContainsString('október 2026', $text);
        $this->assertStringContainsString($this->norm($snap['report']['rows'][0]['billed']), $text);
        $this->assertStringContainsString($this->norm(PdfFormat::amount('1309.50', 'EUR', 'sk')), $text);
        $this->assertStringContainsString('Nie som platiteľ DPH.', $text);
    }

    public function testUnsafeUrlAndHtmlTitleAreText(): void
    {
        $snap                           = SampleSnapshots::invoice('sk');
        $snap['lines'][0]['portal_url'] = 'javascript:x';
        $snap['lines'][0]['title']      = '<img src=http://x>';
        $snap['report']                 = array_replace($snap['report'], ReportRows::build($snap['lines'], $snap['labels']['report'], 'sk'));
        $pdf                            = (new MpdfReportRep2Renderer())->render($snap);

        $this->assertStringNotContainsString('javascript:', $pdf->bytes);
        $text = PdfText::extract($pdf->bytes);

        if ($text === null) {
            $this->markTestSkipped('pdftotext is not available');
        }

        $this->assertStringContainsString('<img src=http://x>', $text);
    }

    public function testRateColumnOnlyWhenRatesDiffer(): void
    {
        $this->assertStringContainsString('Sadzba: ', $this->text(SampleSnapshots::invoice('sk')));

        $mixed                     = SampleSnapshots::invoice('sk');
        $mixed['lines'][1]['rate'] = '30.00';
        $mixed['report']           = array_replace($mixed['report'], ReportRows::build($mixed['lines'], $mixed['labels']['report'], 'sk'));
        $text                      = $this->text($mixed);
        $this->assertStringNotContainsString('Sadzba: ', $text);
        $this->assertStringContainsString('Sadzba', $text);
    }

    public function testMissingNumberThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MpdfReportRep2Renderer())->render(SampleSnapshots::invoice('sk', null, ['number' => null]));
    }
}
