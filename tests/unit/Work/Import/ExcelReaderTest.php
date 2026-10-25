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
        self::assertSame('27.00', self::$sheet->rate);
        self::assertCount(2, self::$sheet->blocks);
        self::assertSame('2026-09', self::$sheet->blocks[0]->month);
        self::assertSame('2026-10', self::$sheet->blocks[1]->month);
        self::assertSame(4, self::$sheet->blocks[0]->firstRow);
        self::assertSame(8, self::$sheet->blocks[0]->lastRow);
        self::assertCount(5, self::$sheet->blocks[0]->rows);
    }

    public function testUrlsFromHyperlinkTextAndRejectedScheme(): void
    {
        $rows = self::$sheet->blocks[0]->rows;

        self::assertSame('https://taskman.example/?section=management&project=1&todo=1', $rows[0]->url);
        self::assertTrue($rows[0]->specificLink);
        self::assertSame('https://taskman.example/?section=mana', $rows[3]->url);
        self::assertFalse($rows[3]->specificLink);
        self::assertNull($rows[4]->url);
    }

    public function testHoursAndDoneNormalization(): void
    {
        $rows = self::$sheet->blocks[0]->rows;

        self::assertSame('8.00', $rows[0]->hours);
        self::assertSame('8.50', $rows[1]->hours);
        self::assertSame('1000.00', $rows[2]->hours);
        self::assertSame('1.50', $rows[4]->hours);

        self::assertTrue($rows[0]->done);
        self::assertTrue($rows[1]->done);
        self::assertTrue($rows[2]->done);
        self::assertTrue($rows[3]->done);
        self::assertFalse($rows[4]->done);
        self::assertFalse($rows[0]->billed);
        self::assertSame('216.00', $rows[0]->excelAmount);
    }

    public function testSumCellsKeepFormulaAndStoredValue(): void
    {
        $sums = self::$sheet->blocks[1]->sums;

        self::assertSame('=SUM(F14:F16)', $sums['F']['formula']);
        self::assertSame('27.00', $sums['F']['value']);
        self::assertSame('=SUM(C14:C18)', $sums['C']['formula']);
        self::assertSame('11.50', $sums['C']['value']);
        self::assertSame('100.00', self::$sheet->blocks[1]->rows[1]->excelAmount);
    }

    public function testMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ExcelReader())->read('/nonexistent/file.xlsx');
    }
}
