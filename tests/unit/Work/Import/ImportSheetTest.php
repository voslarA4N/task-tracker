<?php

declare(strict_types=1);

namespace Tests\Unit\Work\Import;

use App\Libraries\Work\Import\ImportBlock;
use App\Libraries\Work\Import\ImportSheet;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ImportSheetTest extends TestCase
{
    private function block(string $month): ImportBlock
    {
        return new ImportBlock($month, 4, 8, [], []);
    }

    public function testForMonthKeepsOnlyRequestedBlock(): void
    {
        $sep   = $this->block('2026-09');
        $oct   = $this->block('2026-10');
        $sheet = new ImportSheet('27.00', [$sep, $oct]);

        $filtered = $sheet->forMonth('2026-09');

        $this->assertSame('27.00', $filtered->rate);
        $this->assertCount(1, $filtered->blocks);
        $this->assertSame($sep, $filtered->blocks[0]);
        $this->assertCount(2, $sheet->blocks);
    }

    public function testForMonthKeepsAllBlocksOfSameMonthInOrder(): void
    {
        $a     = $this->block('2026-09');
        $b     = $this->block('2026-10');
        $c     = $this->block('2026-09');
        $sheet = new ImportSheet(null, [$a, $b, $c]);

        $this->assertSame([$a, $c], $sheet->forMonth('2026-09')->blocks);
    }

    public function testMissingMonthThrowsAndListsFoundMonths(): void
    {
        $sheet = new ImportSheet(null, [$this->block('2026-09'), $this->block('2026-10')]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Mesiac 2026-11 v súbore nie je (nájdené: 2026-09, 2026-10).');
        $sheet->forMonth('2026-11');
    }

    public function testInvalidFormatThrows(): void
    {
        $sheet = new ImportSheet(null, [$this->block('2026-09')]);

        foreach (['2026-9', '2026-13', ''] as $bad) {
            try {
                $sheet->forMonth($bad);
                $this->fail('Expected InvalidArgumentException for "' . $bad . '"');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
