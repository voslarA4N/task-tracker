<?php

declare(strict_types=1);

namespace App\Libraries\Work\Import;

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\Money;
use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Work\BillingMonth;

/**
 * Text suchého behu pre CLI (iba slovenske konstanty, nastroj pre vlastnika). Bez PhpSpreadsheet.
 */
final class ImportReportFormatter
{
    /**
     * @return list<string>
     */
    public function lines(ImportPlan $plan, string $currency, string $locale): array
    {
        $lines = [];

        foreach ($plan->months as $m) {
            $e = $m['excel'];
            $c = $m['calc'];

            $lines[] = BillingMonth::label($m['month'], $locale) . ': Excel '
                . $this->pair($e['quotedHours'], $e['quotedAmount'], $currency, $locale)
                . ', prepočet ' . $this->pair($c['quotedHours'], $c['quotedAmount'], $currency, $locale)
                . '; zapracované Excel ' . $this->pair($e['doneHours'], $e['doneAmount'], $currency, $locale)
                . ', prepočet ' . $this->pair($c['doneHours'], $c['doneAmount'], $currency, $locale);

            foreach ($m['formulaIssues'] as $issue) {
                $lines[] = '  ! ' . $issue;
            }
        }

        foreach ($plan->rowIssues as $issue) {
            $lines[] = 'riadok ' . $issue['row'] . ': ' . $issue['message'];
        }

        if ($plan->rateMismatch !== null) {
            $lines[] = 'Rozdiel sadzby: Excel ' . MoneyFormat::format($plan->rateMismatch['excel'], $currency, $locale)
                . ', klient ' . MoneyFormat::format($plan->rateMismatch['client'], $currency, $locale) . '.';
        }

        foreach ($plan->billedParts as $b) {
            $lines[] = '  ! ' . BillingMonth::label($b['month'], $locale) . ': ' . $b['rows'] . ' riadkov s Fakturované = ÁNO ('
                . $this->pair($b['hours'], $b['amount'], $currency, $locale) . ') bude nezamknutými časťami na fakturáciu.';
        }

        $warning = $this->billedWarning($plan, $currency, $locale);

        if ($warning !== null) {
            $lines[] = $warning;
        }

        $lines[] = 'Riadky bez špecifického odkazu: ' . $plan->genericLinkRows;
        $lines[] = 'Úlohy na import: ' . count($plan->items);
        $lines[] = 'Rozdiely: ' . ($plan->hasDifferences() ? 'áno' : 'nie');

        return $lines;
    }

    /**
     * WR-07: varovanie pred dvojitou fakturaciou riadkov, ktore uz boli fakturovane mimo aplikacie.
     */
    public function billedWarning(ImportPlan $plan, string $currency, string $locale): ?string
    {
        if ($plan->billedParts === []) {
            return null;
        }

        $hours  = '0.00';
        $amount = '0.00';

        foreach ($plan->billedParts as $b) {
            $hours  = Hours::plus($hours, $b['hours']);
            $amount = Money::of($amount)->plus(Money::of($b['amount']))->toDecimalString();
        }

        return 'POZOR: ' . $plan->billedPartRows() . ' riadkov označených Fakturované = ÁNO ('
            . $this->pair($hours, $amount, $currency, $locale)
            . ') sa importuje ako NEZAMKNUTÉ časti a budú ponúknuté na fakturáciu znova. Pred vystavením faktúr ich treba ošetriť.';
    }

    private function pair(?string $hours, ?string $amount, string $currency, string $locale): string
    {
        if ($hours === null || $amount === null) {
            return 'nenačítané (chýba alebo neplatná hodnota)';
        }

        return Hours::format($hours, $locale) . ' h / ' . MoneyFormat::format($amount, $currency, $locale);
    }
}
