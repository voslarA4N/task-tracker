<?php

declare(strict_types=1);

namespace App\Libraries\Work\Import;

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\Money;

/**
 * Vysledok suchého behu importu: poloziky, porovnanie suctov s Excelom a nalezy.
 */
final readonly class ImportPlan
{
    /**
     * @param list<array{row: ImportRow, importKey: string, quotedAmount: string, partHours: ?string}>                                                                                                                                                                   $items
     * @param list<array{month: string, excel: array{quotedHours: ?string, quotedAmount: ?string, doneHours: ?string, doneAmount: ?string}, calc: array{quotedHours: string, quotedAmount: string, doneHours: string, doneAmount: string}, formulaIssues: list<string>}> $months
     * @param list<array{row: int, message: string}>                                                                                                                                                                                                                     $rowIssues
     * @param array{excel: string, client: string}|null                                                                                                                                                                                                                  $rateMismatch
     */
    public function __construct(
        public array $items,
        public array $months,
        public array $rowIssues,
        public ?array $rateMismatch,
        public int $genericLinkRows,
    ) {
    }

    public function hasDifferences(): bool
    {
        if ($this->rowIssues !== [] || $this->rateMismatch !== null) {
            return true;
        }

        foreach ($this->months as $m) {
            if ($m['formulaIssues'] !== []) {
                return true;
            }

            $pairs = [
                ['quotedHours', true], ['quotedAmount', false], ['doneHours', true], ['doneAmount', false],
            ];

            foreach ($pairs as [$key, $isHours]) {
                $excel = $m['excel'][$key];

                if ($excel === null) {
                    continue;
                }

                $same = $isHours
                    ? Hours::compare($excel, $m['calc'][$key]) === 0
                    : Money::of($excel)->equals(Money::of($m['calc'][$key]));

                if (! $same) {
                    return true;
                }
            }
        }

        return false;
    }
}
