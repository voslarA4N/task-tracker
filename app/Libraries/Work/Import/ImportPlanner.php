<?php

declare(strict_types=1);

namespace App\Libraries\Work\Import;

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\Money;
use Normalizer;

/**
 * Cista plánovacia logika importu (suchý beh). Sumy sa vzdy prepocitaju sadzbou klienta,
 * sucty z Excelu sa iba porovnavaju (D-17). Nezavisi od PhpSpreadsheet.
 */
final class ImportPlanner
{
    private const COLUMN_LABELS = [
        'C' => 'nacenene hodiny',
        'D' => 'nacenena suma',
        'E' => 'fakturovatelne hodiny',
        'F' => 'fakturovatelna suma',
    ];

    public function plan(ImportSheet $sheet, string $clientRate, string $clientCurrency): ImportPlan
    {
        $clientMoney = Money::of($clientRate);
        $excelRate   = $sheet->rate;
        $rateForRows = $excelRate !== null ? Money::of($excelRate) : $clientMoney;

        $items     = [];
        $months    = [];
        $rowIssues = [];
        $generic   = 0;
        $seen      = [];

        foreach ($sheet->blocks as $block) {
            $quotedHours  = '0.00';
            $doneHours    = '0.00';
            $quotedAmount = Money::zero();
            $doneAmount   = Money::zero();

            foreach ($block->rows as $row) {
                $amount = $clientMoney->timesHours($row->hours);

                if ($row->excelAmount !== null
                    && ! Money::of($row->excelAmount)->equals($rateForRows->timesHours($row->hours))) {
                    $rowIssues[] = [
                        'row'     => $row->rowNumber,
                        'message' => 'Suma v Exceli ' . $row->excelAmount . ' sa nerovna hodiny × sadzba ('
                            . $rateForRows->timesHours($row->hours)->toDecimalString() . ').',
                    ];
                }

                if (! $row->specificLink) {
                    $generic++;
                }

                $quotedHours  = Hours::plus($quotedHours, $row->hours);
                $quotedAmount = $quotedAmount->plus($amount);

                if ($row->done) {
                    $doneHours  = Hours::plus($doneHours, $row->hours);
                    $doneAmount = $doneAmount->plus($amount);
                }

                $base        = $this->baseKey($row);
                $ordinal     = $seen[$base] ?? 0;
                $seen[$base] = $ordinal + 1;

                $items[] = [
                    'row'          => $row,
                    'importKey'    => hash('sha256', $base . '|' . $ordinal),
                    'quotedAmount' => $amount->toDecimalString(),
                    'partHours'    => $row->done ? $row->hours : null,
                ];
            }

            $months[] = [
                'month' => $block->month,
                'excel' => [
                    'quotedHours'  => $block->sums['C']['value'] ?? null,
                    'quotedAmount' => $block->sums['D']['value'] ?? null,
                    'doneHours'    => $block->sums['E']['value'] ?? null,
                    'doneAmount'   => $block->sums['F']['value'] ?? null,
                ],
                'calc' => [
                    'quotedHours'  => $quotedHours,
                    'quotedAmount' => $quotedAmount->toDecimalString(),
                    'doneHours'    => $doneHours,
                    'doneAmount'   => $doneAmount->toDecimalString(),
                ],
                'formulaIssues' => $this->formulaIssues($block),
            ];
        }

        $mismatch = null;

        if ($excelRate !== null && ! Money::of($excelRate)->equals($clientMoney)) {
            $mismatch = ['excel' => Money::of($excelRate)->toDecimalString(), 'client' => $clientMoney->toDecimalString()];
        }

        return new ImportPlan($items, $months, $rowIssues, $mismatch, $generic);
    }

    /**
     * @return list<string>
     */
    private function formulaIssues(ImportBlock $block): array
    {
        $issues = [];

        foreach (self::COLUMN_LABELS as $col => $label) {
            $formula = $block->sums[$col]['formula'] ?? null;

            if ($formula === null) {
                $issues[] = 'Bunka suctu ' . $col . ' (' . $label . ') nie je vzorec.';

                continue;
            }

            if (preg_match('/^=SUM\(([A-Z]+)(\d+):([A-Z]+)(\d+)\)$/iD', $formula, $m) !== 1) {
                $issues[] = 'Vzorec suctu ' . $col . ' (' . $formula . ') nie je SUM nad jednym rozsahom.';

                continue;
            }

            $sameCol = strtoupper($m[1]) === $col && strtoupper($m[3]) === $col;

            if (! $sameCol || (int) $m[2] !== $block->firstRow || (int) $m[4] !== $block->lastRow) {
                $issues[] = 'Rozsah suctu ' . $col . ' ' . $m[1] . $m[2] . ':' . $m[3] . $m[4]
                    . ' nepokryva riadky bloku ' . $col . $block->firstRow . ':' . $col . $block->lastRow . '.';
            }
        }

        return $issues;
    }

    private function baseKey(ImportRow $row): string
    {
        $title = Normalizer::normalize($row->title, Normalizer::FORM_C);
        $title = preg_replace('/[\s\x{00A0}]+/u', ' ', $title === false ? $row->title : $title) ?? '';

        return $row->month . '|' . trim($row->url ?? '') . '|' . mb_strtolower(trim($title)) . '|' . Hours::of($row->hours);
    }
}
