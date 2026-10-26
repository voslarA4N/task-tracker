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
            'month'         => '2026-09',
            'excel'         => ['quotedHours' => $excelHours, 'quotedAmount' => '2403.00', 'doneHours' => '89.00', 'doneAmount' => '2403.00'],
            'calc'          => ['quotedHours' => '89.00', 'quotedAmount' => '2403.00', 'doneHours' => '89.00', 'doneAmount' => '2403.00'],
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

    public function testBilledRowsWarning(): void
    {
        // WR-07: suchy beh aj ostry beh musia jasne povedat, kolko uz fakturovanych riadkov bude znova na fakturaciu.
        $base  = $this->plan([]);
        $plan  = new ImportPlan([], $base->months, [], null, 11, [['month' => '2026-09', 'rows' => 3, 'hours' => '4.50', 'amount' => '121.50']]);
        $f     = new ImportReportFormatter();
        $lines = $f->lines($plan, 'EUR', 'sk');
        $text  = implode("\n", $lines);

        $this->assertStringContainsString('September 2026: 3 riadkov s Fakturované = ÁNO (4,5 h / 121,50', $text);
        $this->assertStringContainsString('POZOR: 3 riadkov označených Fakturované = ÁNO', $text);
        $this->assertSame('Rozdiely: nie', end($lines));
        $this->assertNotNull($f->billedWarning($plan, 'EUR', 'sk'));
        $this->assertNull($f->billedWarning($base, 'EUR', 'sk'));
        $this->assertStringNotContainsString('POZOR', implode("\n", $f->lines($base, 'EUR', 'sk')));
    }

    public function testUnreadableExcelSumIsVisible(): void
    {
        $lines = (new ImportReportFormatter())->lines($this->plan([], null), 'EUR', 'sk');

        $this->assertStringContainsString('nenačítané', $lines[0]);
    }
}
