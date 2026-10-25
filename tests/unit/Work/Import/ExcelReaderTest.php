<?php

declare(strict_types=1);

namespace Tests\Unit\Work\Import;

use App\Libraries\Work\Import\ExcelReader;
use App\Libraries\Work\Import\ImportSheet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImportFixture;

/**
 * @internal
 */
final class ExcelReaderTest extends TestCase
{
    private static ImportSheet $sheet;

    public static function setUpBeforeClass(): void
    {
        ImportFixture::write(ImportFixture::path());
        self::$sheet = (new ExcelReader())->read(ImportFixture::path());
    }

    public function testRateAndBlocks(): void
    {
        $this->assertSame('27.00', self::$sheet->rate);
        $this->assertCount(2, self::$sheet->blocks);
        $this->assertSame('2026-09', self::$sheet->blocks[0]->month);
        $this->assertSame('2026-10', self::$sheet->blocks[1]->month);
        $this->assertSame(4, self::$sheet->blocks[0]->firstRow);
        $this->assertSame(8, self::$sheet->blocks[0]->lastRow);
        $this->assertCount(5, self::$sheet->blocks[0]->rows);
    }

    public function testUrlsFromHyperlinkTextAndRejectedScheme(): void
    {
        $rows = self::$sheet->blocks[0]->rows;

        $this->assertSame('https://taskman.example/?section=management&project=1&todo=1', $rows[0]->url);
        $this->assertTrue($rows[0]->specificLink);
        $this->assertSame('https://taskman.example/?section=mana', $rows[3]->url);
        $this->assertFalse($rows[3]->specificLink);
        $this->assertNull($rows[4]->url);
    }

    public function testHoursAndDoneNormalization(): void
    {
        $rows = self::$sheet->blocks[0]->rows;

        $this->assertSame('8.00', $rows[0]->hours);
        $this->assertSame('8.50', $rows[1]->hours);
        $this->assertSame('1000.00', $rows[2]->hours);
        $this->assertSame('1.50', $rows[4]->hours);

        $this->assertTrue($rows[0]->done);
        $this->assertTrue($rows[1]->done);
        $this->assertTrue($rows[2]->done);
        $this->assertTrue($rows[3]->done);
        $this->assertFalse($rows[4]->done);
        $this->assertFalse($rows[0]->billed);
        $this->assertSame('216.00', $rows[0]->excelAmount);
    }

    public function testSumCellsKeepFormulaAndStoredValue(): void
    {
        $sums = self::$sheet->blocks[1]->sums;

        $this->assertSame('=SUM(F14:F16)', $sums['F']['formula']);
        $this->assertSame('27.00', $sums['F']['value']);
        $this->assertSame('=SUM(C14:C18)', $sums['C']['formula']);
        $this->assertSame('11.50', $sums['C']['value']);
        $this->assertSame('100.00', self::$sheet->blocks[1]->rows[1]->excelAmount);
    }

    public function testMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ExcelReader())->read('/nonexistent/file.xlsx');
    }
}
