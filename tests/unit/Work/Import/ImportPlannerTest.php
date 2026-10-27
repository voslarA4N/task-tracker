<?php

declare(strict_types=1);

namespace Tests\Unit\Work\Import;

use App\Libraries\Work\Import\ImportBlock;
use App\Libraries\Work\Import\ImportPlanner;
use App\Libraries\Work\Import\ImportReportFormatter;
use App\Libraries\Work\Import\ImportRow;
use App\Libraries\Work\Import\ImportSheet;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ImportPlannerTest extends TestCase
{
    private function row(int $n, string $hours, bool $done, ?string $amount = null, bool $specific = true, string $title = 'Task', string $month = '2026-10'): ImportRow
    {
        return new ImportRow($n, $month, 'https://t.example/?todo=' . $n, $specific, $title, $hours, $amount, $done, false);
    }

    /**
     * @param list<ImportRow> $rows
     */
    private function block(array $rows, string $fRange, string $fValue, int $first = 19, int $last = 23, string $month = '2026-10'): ImportBlock
    {
        return new ImportBlock($month, $first, $last, $rows, [
            'C' => ['value' => '82.00', 'formula' => '=SUM(C' . $first . ':C' . $last . ')'],
            'D' => ['value' => '2214.00', 'formula' => '=SUM(D' . $first . ':D' . $last . ')'],
            'E' => ['value' => '12.00', 'formula' => '=SUM(E' . $first . ':E' . $last . ')'],
            'F' => ['value' => $fValue, 'formula' => '=SUM(' . $fRange . ')'],
        ]);
    }

    /**
     * @return list<ImportRow>
     */
    private function octRows(): array
    {
        return [
            $this->row(19, '40.00', false, '1080.00'),
            $this->row(20, '20.00', false, '540.00'),
            $this->row(21, '10.00', false, '270.00'),
            $this->row(22, '1.00', true, '27.00'),
            $this->row(23, '11.00', true, '297.00'),
        ];
    }

    public function testIncompleteRangeAndDifferentValue(): void
    {
        $plan = (new ImportPlanner())->plan(new ImportSheet('27.00', [$this->block($this->octRows(), 'F19:F22', '27.00')]), '27.00', 'EUR');

        $this->assertSame('324.00', $plan->months[0]['calc']['doneAmount']);
        $this->assertSame('27.00', $plan->months[0]['excel']['doneAmount']);
        $this->assertSame('82.00', $plan->months[0]['calc']['quotedHours']);
        $this->assertSame('12.00', $plan->months[0]['calc']['doneHours']);
        $this->assertStringContainsString('F19:F22', implode(' ', $plan->months[0]['formulaIssues']));
        $this->assertTrue($plan->hasDifferences());
    }

    public function testCompleteRangeHasNoFormulaIssues(): void
    {
        $plan = (new ImportPlanner())->plan(new ImportSheet('27.00', [$this->block($this->octRows(), 'F19:F23', '324.00')]), '27.00', 'EUR');

        $this->assertSame([], $plan->months[0]['formulaIssues']);
        $this->assertSame([], $plan->rowIssues);
        $this->assertNull($plan->rateMismatch);
    }

    public function testIncompleteRangeWithEqualValuesStillReported(): void
    {
        $plan = (new ImportPlanner())->plan(new ImportSheet('27.00', [$this->block($this->octRows(), 'F19:F22', '324.00')]), '27.00', 'EUR');

        $this->assertNotSame([], $plan->months[0]['formulaIssues']);
        $this->assertTrue($plan->hasDifferences());
    }

    public function testRowAmountMismatch(): void
    {
        $rows  = [$this->row(5, '3.00', false, '100.00')];
        $block = new ImportBlock('2026-10', 5, 5, $rows, []);
        $plan  = (new ImportPlanner())->plan(new ImportSheet('27.00', [$block]), '27.00', 'EUR');

        $this->assertSame(5, $plan->rowIssues[0]['row']);
    }

    public function testRateMismatchUsesClientRate(): void
    {
        $block = new ImportBlock('2026-10', 5, 5, [$this->row(5, '2.00', false, '54.00')], []);
        $plan  = (new ImportPlanner())->plan(new ImportSheet('27.00', [$block]), '30.00', 'EUR');

        $this->assertSame(['excel' => '27.00', 'client' => '30.00'], $plan->rateMismatch);
        $this->assertSame('60.00', $plan->items[0]['quotedAmount']);
        $this->assertSame([], $plan->rowIssues);
    }

    public function testPartHours(): void
    {
        $plan = (new ImportPlanner())->plan(new ImportSheet('27.00', [$this->block($this->octRows(), 'F19:F23', '324.00')]), '27.00', 'EUR');

        $this->assertNull($plan->items[0]['partHours']);
        $this->assertSame('1.00', $plan->items[3]['partHours']);
    }

    public function testBilledDoneRowsAreSummarizedPerMonth(): void
    {
        // WR-07: riadky Fakturovane = ANO so zapracovanim sa importuju ako nezamknute casti, plan ich spocita.
        $sep = [
            new ImportRow(4, '2026-09', 'https://t.example/?todo=4', true, 'A', '2.00', null, true, true),
            new ImportRow(5, '2026-09', 'https://t.example/?todo=5', true, 'B', '1.50', null, true, true),
            new ImportRow(6, '2026-09', 'https://t.example/?todo=6', true, 'C', '3.00', null, true, false),
            // ANO bez zapracovania: cast nevznikne, nie je co znova fakturovat.
            new ImportRow(7, '2026-09', 'https://t.example/?todo=7', true, 'D', '5.00', null, false, true),
        ];
        $plan = (new ImportPlanner())->plan(new ImportSheet('27.00', [
            $this->block($sep, 'F4:F7', '0.00', 4, 7, '2026-09'),
            $this->block($this->octRows(), 'F19:F23', '324.00'),
        ]), '27.00', 'EUR');

        $this->assertSame([['month' => '2026-09', 'rows' => 2, 'hours' => '3.50', 'amount' => '94.50']], $plan->billedParts);
        $this->assertSame(2, $plan->billedPartRows());
    }

    public function testImportKeyStableAndOrdinalDistinguishesDuplicates(): void
    {
        $rows = [
            $this->row(5, '2.00', false, null, true, 'Same  Title'),
            $this->row(6, '2.00', false, null, true, ' same title '),
        ];
        // equal url needed for the duplicate case
        $rows[1] = new ImportRow(6, '2026-10', $rows[0]->url, true, ' same title ', '2.00', null, false, false);
        $sheet   = new ImportSheet(null, [new ImportBlock('2026-10', 5, 6, $rows, [])]);
        $planner = new ImportPlanner();
        $a       = $planner->plan($sheet, '27.00', 'EUR');
        $b       = $planner->plan($sheet, '27.00', 'EUR');

        $this->assertNotSame($a->items[0]['importKey'], $a->items[1]['importKey']);
        $this->assertSame($a->items[0]['importKey'], $b->items[0]['importKey']);
        $this->assertSame($a->items[1]['importKey'], $b->items[1]['importKey']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $a->items[0]['importKey']);
    }

    public function testGenericLinkRows(): void
    {
        $rows = [$this->row(5, '1.00', false, null, false), $this->row(6, '1.00', false, null, true), $this->row(7, '1.00', false, null, false)];
        $plan = (new ImportPlanner())->plan(new ImportSheet(null, [new ImportBlock('2026-10', 5, 7, $rows, [])]), '27.00', 'EUR');

        $this->assertSame(2, $plan->genericLinkRows);
    }

    public function testForMonthPlanMatchesSeptemberSubsetOfFullPlan(): void
    {
        $sep = [
            new ImportRow(4, '2026-09', 'https://t.example/?todo=4', true, 'A', '2.00', null, true, true),
            new ImportRow(5, '2026-09', 'https://t.example/?todo=5', true, 'B', '1.50', null, true, true),
            new ImportRow(6, '2026-09', 'https://t.example/?todo=6', false, 'C', '3.00', null, true, false),
            new ImportRow(7, '2026-09', 'https://t.example/?todo=7', true, 'D', '5.00', null, false, false),
        ];
        $oct = [
            new ImportRow(14, '2026-10', 'https://t.example/?todo=14', true, 'E', '4.00', null, true, true),
            new ImportRow(15, '2026-10', 'https://t.example/?todo=15', false, 'F', '1.00', null, false, false),
        ];
        $sheet = new ImportSheet('27.00', [
            $this->block($sep, 'F4:F7', '0.00', 4, 7, '2026-09'),
            $this->block($oct, 'F14:F15', '0.00', 14, 15, '2026-10'),
        ]);
        $planner = new ImportPlanner();
        $full    = $planner->plan($sheet, '27.00', 'EUR');
        $plan    = $planner->plan($sheet->forMonth('2026-09'), '27.00', 'EUR');

        $this->assertCount(1, $plan->months);
        $this->assertSame('2026-09', $plan->months[0]['month']);
        $this->assertSame($full->months[0]['calc'], $plan->months[0]['calc']);
        $this->assertSame([$full->billedParts[0]], $plan->billedParts);
        $this->assertSame(2, $plan->billedPartRows());
        $this->assertSame(
            array_column(array_slice($full->items, 0, 4), 'importKey'),
            array_column($plan->items, 'importKey'),
        );
        $this->assertSame(1, $plan->genericLinkRows);

        $warning = (new ImportReportFormatter())->billedWarning($plan, 'EUR', 'sk');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('POZOR: 2 riadkov', $warning);
        $this->assertStringContainsString('94,50', $warning);
    }
}
