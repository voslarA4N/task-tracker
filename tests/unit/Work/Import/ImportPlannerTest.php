<?php

declare(strict_types=1);

namespace Tests\Unit\Work\Import;

use App\Libraries\Work\Import\ImportBlock;
use App\Libraries\Work\Import\ImportPlanner;
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

        self::assertSame('324.00', $plan->months[0]['calc']['doneAmount']);
        self::assertSame('27.00', $plan->months[0]['excel']['doneAmount']);
        self::assertSame('82.00', $plan->months[0]['calc']['quotedHours']);
        self::assertSame('12.00', $plan->months[0]['calc']['doneHours']);
        self::assertStringContainsString('F19:F22', implode(' ', $plan->months[0]['formulaIssues']));
        self::assertTrue($plan->hasDifferences());
    }

    public function testCompleteRangeHasNoFormulaIssues(): void
    {
        $plan = (new ImportPlanner())->plan(new ImportSheet('27.00', [$this->block($this->octRows(), 'F19:F23', '324.00')]), '27.00', 'EUR');

        self::assertSame([], $plan->months[0]['formulaIssues']);
        self::assertSame([], $plan->rowIssues);
        self::assertNull($plan->rateMismatch);
    }

    public function testIncompleteRangeWithEqualValuesStillReported(): void
    {
        $plan = (new ImportPlanner())->plan(new ImportSheet('27.00', [$this->block($this->octRows(), 'F19:F22', '324.00')]), '27.00', 'EUR');

        self::assertNotSame([], $plan->months[0]['formulaIssues']);
        self::assertTrue($plan->hasDifferences());
    }

    public function testRowAmountMismatch(): void
    {
        $rows    = [$this->row(5, '3.00', false, '100.00')];
        $block   = new ImportBlock('2026-10', 5, 5, $rows, []);
        $plan    = (new ImportPlanner())->plan(new ImportSheet('27.00', [$block]), '27.00', 'EUR');

        self::assertSame(5, $plan->rowIssues[0]['row']);
    }

    public function testRateMismatchUsesClientRate(): void
    {
        $block = new ImportBlock('2026-10', 5, 5, [$this->row(5, '2.00', false, '54.00')], []);
        $plan  = (new ImportPlanner())->plan(new ImportSheet('27.00', [$block]), '30.00', 'EUR');

        self::assertSame(['excel' => '27.00', 'client' => '30.00'], $plan->rateMismatch);
        self::assertSame('60.00', $plan->items[0]['quotedAmount']);
        self::assertSame([], $plan->rowIssues);
    }

    public function testPartHours(): void
    {
        $plan = (new ImportPlanner())->plan(new ImportSheet('27.00', [$this->block($this->octRows(), 'F19:F23', '324.00')]), '27.00', 'EUR');

        self::assertNull($plan->items[0]['partHours']);
        self::assertSame('1.00', $plan->items[3]['partHours']);
    }

    public function testImportKeyStableAndOrdinalDistinguishesDuplicates(): void
    {
        $rows  = [
            $this->row(5, '2.00', false, null, true, 'Same  Title'),
            $this->row(6, '2.00', false, null, true, ' same title '),
        ];
        // equal url needed for the duplicate case
        $rows[1] = new ImportRow(6, '2026-10', $rows[0]->url, true, ' same title ', '2.00', null, false, false);
        $sheet   = new ImportSheet(null, [new ImportBlock('2026-10', 5, 6, $rows, [])]);
        $planner = new ImportPlanner();
        $a       = $planner->plan($sheet, '27.00', 'EUR');
        $b       = $planner->plan($sheet, '27.00', 'EUR');

        self::assertNotSame($a->items[0]['importKey'], $a->items[1]['importKey']);
        self::assertSame($a->items[0]['importKey'], $b->items[0]['importKey']);
        self::assertSame($a->items[1]['importKey'], $b->items[1]['importKey']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $a->items[0]['importKey']);
    }

    public function testGenericLinkRows(): void
    {
        $rows  = [$this->row(5, '1.00', false, null, false), $this->row(6, '1.00', false, null, true), $this->row(7, '1.00', false, null, false)];
        $plan  = (new ImportPlanner())->plan(new ImportSheet(null, [new ImportBlock('2026-10', 5, 7, $rows, [])]), '27.00', 'EUR');

        self::assertSame(2, $plan->genericLinkRows);
    }
}
