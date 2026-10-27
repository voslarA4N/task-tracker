<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\MpdfInvoiceInv2Renderer;
use App\Libraries\Invoicing\PdfFormat;
use App\Libraries\Invoicing\Samples\SampleSnapshots;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PdfText;

/**
 * @internal
 */
#[Group('integration')]
final class MpdfInvoiceInv2RendererTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $snapshot
     */
    private function text(array $snapshot): string
    {
        $pdf  = (new MpdfInvoiceInv2Renderer())->render($snapshot);
        $text = PdfText::extract($pdf->bytes);

        if ($text === null) {
            $this->markTestSkipped('pdftotext is not available');
        }

        return $text;
    }

    public function testRendersPdfWithHash(): void
    {
        $pdf = (new MpdfInvoiceInv2Renderer())->render(SampleSnapshots::invoice('sk'));

        $this->assertStringStartsWith('%PDF-', $pdf->bytes);
        $this->assertSame('inv-2', $pdf->templateVersion);
        $this->assertSame(hash('sha256', $pdf->bytes), $pdf->sha256);
        $this->assertStringStartsWith('mpdf 8.', $pdf->renderer);
    }

    public function testSlovakNonVat(): void
    {
        $text = $this->text(SampleSnapshots::invoice('sk'));

        $this->assertStringContainsString('Faktúra 202610001', $text);
        $this->assertStringContainsString('Nie som platiteľ DPH.', $text);
        $this->assertStringContainsString('Zapísaný v živnostenskom registri', $text);
        $this->assertStringContainsString((string) preg_replace('/[\\s\\x{00A0}\\x{202F}]+/u', ' ', PdfFormat::amount('1309.50', 'EUR', 'sk')), $text);
        $this->assertStringNotContainsString('IČ DPH', $text);
        $this->assertStringNotContainsString('daňový doklad', $text);
    }

    public function testPolishArt113(): void
    {
        $text = $this->text(SampleSnapshots::invoice('pl'));

        $this->assertStringContainsString('Faktura', $text);
        $this->assertStringContainsString('NIP', $text);
        $this->assertStringContainsString('Tytuł płatności', $text);
        $this->assertStringContainsString('202610001', $text);
        $this->assertStringNotContainsString('REGON', $text);
        $this->assertStringNotContainsString('Variabilny', $text);
        $this->assertStringNotContainsString('Faktura VAT', $text);
    }

    public function testEuB2bPrintsClientVatId(): void
    {
        $text = $this->text(SampleSnapshots::invoice('pl_eu_b2b'));

        $this->assertStringContainsString('SK2020123456', $text);
    }

    public function testEnglishAndDiacritics(): void
    {
        $text = $this->text(SampleSnapshots::invoice('pl_non_eu'));

        $this->assertStringContainsString('Invoice', $text);
        $this->assertStringContainsString('Zażółć gęślą jaźń', $text);
        $this->assertStringContainsString('Ľščťžýáíé', $text);
        $this->assertStringContainsString('ěřůďňť', $text);
    }

    public function testCzech(): void
    {
        $text = $this->text(SampleSnapshots::invoice('cz'));

        $this->assertStringContainsString('Faktura 202610001', $text);
        $this->assertStringContainsString('Nejsem plátce DPH.', $text);
    }

    public function testHtmlInTitleIsEscaped(): void
    {
        $s                      = SampleSnapshots::invoice('sk');
        $s['lines'][0]['title'] = '<img src=http://x>';

        $this->assertStringContainsString('<img src=http://x>', $this->text($s));
    }

    public function testCashPaymentHidesIban(): void
    {
        $s            = SampleSnapshots::invoice('sk');
        $s['payment'] = ['method' => 'cash', 'note' => null, 'qr' => null];
        $text         = $this->text($s);

        $this->assertStringContainsString('Hotovosť', $text);
        $this->assertStringNotContainsString($s['bank']['iban'], $text);
    }

    public function testNullNumberRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MpdfInvoiceInv2Renderer())->render(SampleSnapshots::invoice('sk', null, ['number' => null]));
    }

    public function testTemplateIsSnapshotOnly(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Views/pdf/invoice_inv2.php');

        $this->assertSame(0, preg_match('/lang\(|service\(|db_connect|<style|style=/', $src));
    }

    public function testQrIsVectorWithCaption(): void
    {
        $s     = SampleSnapshots::invoice('sk');
        $bytes = (new MpdfInvoiceInv2Renderer())->render($s)->bytes;

        $this->assertNotNull($s['payment']['qr']);
        $this->assertStringContainsString('/Subtype /Form', $bytes);
        $this->assertStringNotContainsString('/Subtype /Image', $bytes);

        $text = $this->text($s);
        $this->assertStringContainsString('PAY', $text);
        $this->assertStringContainsString('by square', $text);
        $this->assertStringContainsString('Platba QR kódom', $text);
    }

    public function testCashHasNoQr(): void
    {
        $s            = SampleSnapshots::invoice('sk');
        $s['payment'] = ['method' => 'cash', 'note' => null, 'qr' => $s['payment']['qr']];
        $bytes        = (new MpdfInvoiceInv2Renderer())->render($s)->bytes;

        $this->assertStringNotContainsString('/Subtype /Form', $bytes);
        $this->assertStringNotContainsString('Platba QR kódom', $this->text($s));
    }
}
