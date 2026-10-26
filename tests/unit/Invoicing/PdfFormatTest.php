<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\PdfFormat;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class PdfFormatTest extends CIUnitTestCase
{
    public function testAmount(): void
    {
        $this->assertSame("1\u{00A0}080,00\u{00A0}€", PdfFormat::amount('1080.00', 'EUR', 'sk'));
        $this->assertSame("1\u{00A0}234\u{00A0}567,50\u{00A0}zł", PdfFormat::amount('1234567.50', 'PLN', 'pl'));
        $this->assertSame("5,00\u{00A0}Kč", PdfFormat::amount('5.00', 'CZK', 'cs'));
        $this->assertSame("1,080.00\u{00A0}€", PdfFormat::amount('1080.00', 'EUR', 'en'));
        $this->assertSame("-229,50\u{00A0}€", PdfFormat::amount('-229.50', 'EUR', 'sk'));
        $this->assertSame("0,00\u{00A0}€", PdfFormat::amount('0.00', 'EUR', 'sk'));
    }

    public function testInvalidAmount(): void
    {
        foreach (['1,5', 'abc', '1.5', ''] as $bad) {
            try {
                PdfFormat::amount($bad, 'EUR', 'sk');
                $this->fail('expected exception for ' . $bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHours(): void
    {
        $this->assertSame('8,50', PdfFormat::hours('8.50', 'sk'));
        $this->assertSame('8.50', PdfFormat::hours('8.50', 'en'));
        $this->assertSame('40,00', PdfFormat::hours('40.00', 'cs'));
    }

    public function testDate(): void
    {
        $this->assertSame('26. 10. 2026', PdfFormat::date('2026-10-26', 'sk'));
        $this->assertSame('26. 10. 2026', PdfFormat::date('2026-10-26', 'cs'));
        $this->assertSame('26.10.2026', PdfFormat::date('2026-10-26', 'pl'));
        $this->assertSame('2026-10-26', PdfFormat::date('2026-10-26', 'en'));
        $this->assertSame('5. 9. 2026', PdfFormat::date('2026-09-05', 'sk'));
        $this->assertSame('05.09.2026', PdfFormat::date('2026-09-05', 'pl'));
    }

    public function testLabels(): void
    {
        $this->assertSame('Faktúra', lang('InvoicePdf.invoice', [], 'sk'));
        $this->assertSame('Faktura', lang('InvoicePdf.invoice', [], 'pl'));
        $this->assertSame('Faktura', lang('InvoicePdf.invoice', [], 'cs'));
        $this->assertSame('Invoice', lang('InvoicePdf.invoice', [], 'en'));

        $keys = array_keys(require APPPATH . 'Language/sk/InvoicePdf.php');

        foreach (['en', 'pl', 'cs'] as $l) {
            $this->assertSame($keys, array_keys(require APPPATH . "Language/{$l}/InvoicePdf.php"));
        }
    }
}
