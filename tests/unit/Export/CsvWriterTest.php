<?php

declare(strict_types=1);

namespace Tests\Unit\Export;

use App\Libraries\Export\CsvWriter;
use App\Libraries\Export\ExportCell;
use App\Libraries\Export\ExportTable;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CsvWriterTest extends TestCase
{
    /**
     * @param list<ExportCell> $row
     */
    private function one(array $row): string
    {
        $csv = CsvWriter::render(new ExportTable('s', array_fill(0, count($row), 'H'), [$row]));

        return explode("\n", $csv)[1];
    }

    public function testBomAndDelimiter(): void
    {
        $csv = CsvWriter::render(new ExportTable('s', ['A', 'B'], [[ExportCell::text('x'), ExportCell::text('y')]]));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame("\xEF\xBB\xBFA;B\nx;y\n", $csv);
    }

    public function testEmptyTableIsBomAndHeader(): void
    {
        $this->assertSame("\xEF\xBB\xBFA;B\n", CsvWriter::render(new ExportTable('s', ['A', 'B'], [])));
    }

    public function testMoneyUsesDecimalComma(): void
    {
        $this->assertSame('1080,50', $this->one([ExportCell::money('1080.50')]));
    }

    public function testNegativeMoneyIsNotPrefixed(): void
    {
        $this->assertSame('-1080,50', $this->one([ExportCell::money('-1080.50')]));
    }

    public function testDecimalRate(): void
    {
        $this->assertSame('4,39450000', $this->one([ExportCell::decimal('4.39450000')]));
    }

    public function testFormulaTextIsPrefixed(): void
    {
        $this->assertSame('"\'=HYPERLINK(""x"")"', $this->one([ExportCell::text('=HYPERLINK("x")')]));
    }

    public function testQuotingAndDiacritics(): void
    {
        $this->assertSame('"a;b ""q"""', $this->one([ExportCell::text('a;b "q"')]));
        $this->assertSame('Ďakujeme', $this->one([ExportCell::text('Ďakujeme')]));
    }

    public function testNullTextIsEmpty(): void
    {
        $this->assertSame('', $this->one([ExportCell::text(null)]));
    }
}
