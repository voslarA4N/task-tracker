<?php

declare(strict_types=1);

namespace Tests\Unit\Work\Import;

use App\Libraries\Work\Import\ImportPlan;
use App\Libraries\Work\Import\ImportReportFormatter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ImportReportFormatterTest extends CIUnitTestCase
{
    /**
     * @param list<string> $issues
     */
    private function plan(array $issues, ?string $excelHours = '89.00'): ImportPlan
    {
        $months = [[
            'month' => '2026-09',
            'excel' => ['quotedHours' => $excelHours, 'quotedAmount' => '2403.00', 'doneHours' => '89.00', 'doneAmount' => '2403.00'],
            'calc'  => ['quotedHours' => '89.00', 'quotedAmount' => '2403.00', 'doneHours' => '89.00', 'doneAmount' => '2403.00'],
            'formulaIssues' => $issues,
        ]];

        return new ImportPlan([], $months, [], null, 11);
    }

    public function testNoDifferences(): void
    {
        $lines = (new ImportReportFormatter())->lines($this->plan([]), 'EUR', 'sk');

        $this->assertSame('Rozdiely: nie', end($lines));
        $this->assertStringContainsString('€', $lines[0]);
        $this->assertContains('Riadky bez špecifického odkazu: 11', $lines);
    }

    public function testFormulaIssueIsListed(): void
    {
        $lines = (new ImportReportFormatter())->lines($this->plan(['Rozsah suctu F nepokryva riadky']), 'EUR', 'sk');

        $this->assertStringContainsString('Rozsah suctu F', implode("\n", $lines));
        $this->assertSame('Rozdiely: áno', end($lines));
    }

    public function testUnreadableExcelSumIsVisible(): void
    {
        $lines = (new ImportReportFormatter())->lines($this->plan([], null), 'EUR', 'sk');

        $this->assertStringContainsString('nenačítané', $lines[0]);
    }
}
