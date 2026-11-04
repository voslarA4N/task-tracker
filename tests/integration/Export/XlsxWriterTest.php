<?php

declare(strict_types=1);

namespace Tests\Integration\Export;

use App\Libraries\Export\ExportCell;
use App\Libraries\Export\ExportTable;
use App\Libraries\Export\XlsxWriter;
use App\Libraries\Platform\Money;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\XlsxReader;

/**
 * @internal
 */
final class XlsxWriterTest extends CIUnitTestCase
{
    private function bytes(): string
    {
        $texts  = ['=1+1', '+cmd', '-2', '@SUM(A1)', "\tx"];
        $rows   = [array_map(ExportCell::text(...), $texts)];
        $rows[] = [ExportCell::money('0.10'), ExportCell::money('0.07'), ExportCell::money('9999999999.99'), ExportCell::money('-1080.50'), ExportCell::money('1080.50')];
        $rows[] = [ExportCell::decimal('4.39450000')];

        return XlsxWriter::render(new ExportTable('Test', ['A', 'B', 'C', 'D', 'E'], $rows, [20, 12, 14, 14, 14]));
    }

    public function testNoFormulasAndTextStaysText(): void
    {
        $b = $this->bytes();
        $this->assertSame('PK', substr($b, 0, 2));
        $this->assertStringNotContainsString('<f>', XlsxReader::sheetXml($b));
        $this->assertStringNotContainsString('<f ', XlsxReader::sheetXml($b));

        $cells = XlsxReader::cells($b);
        $this->assertCount(4, $cells);
        $texts = ['=1+1', '+cmd', '-2', '@SUM(A1)', "\tx"];

        foreach ($texts as $i => $t) {
            $this->assertSame('inlineStr', $cells[1][$i]['type']);
            $this->assertSame($t, $cells[1][$i]['value']);
        }
    }

    public function testNumbersAreNumericWithFormat(): void
    {
        $b     = $this->bytes();
        $cells = XlsxReader::cells($b);
        $exp   = ['0.10', '0.07', '9999999999.99', '-1080.50', '1080.50'];

        foreach ($exp as $i => $v) {
            $this->assertNotSame('inlineStr', $cells[2][$i]['type']);
            $this->assertStringNotContainsStringIgnoringCase('e', $cells[2][$i]['value']);
            $this->assertTrue(Money::of($cells[2][$i]['value'])->equals(Money::of($v)), $cells[2][$i]['value']);
        }
        // '#,##0.00' je vstavany format c. 4, preto sa v numFmts nevypisuje, ale cellXfs ho odkazuje.
        $this->assertStringContainsString('numFmtId="4"', XlsxReader::stylesXml($b));
        $this->assertContains('0.0000####', XlsxReader::numFmts($b));
        $this->assertNotSame('inlineStr', $cells[3][0]['type']);
        $this->assertStringStartsWith('4.3945', $cells[3][0]['value']);
    }

    public function testHeaderBoldFrozenAndTempFileRemoved(): void
    {
        $b = $this->bytes();
        $this->assertStringContainsString('<b', XlsxReader::stylesXml($b));
        $this->assertStringContainsString('<pane', XlsxReader::sheetXml($b));
        $this->assertSame([], glob(WRITEPATH . 'exports/xlsx*') ?: []);
    }
}
