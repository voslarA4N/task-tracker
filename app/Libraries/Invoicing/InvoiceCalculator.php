<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\Money;

/**
 * Jedine miesto vypoctu poloziek a suctu faktury (D-08): riadok = round(hodiny x sadzba, 2) half-up,
 * suma faktury = sucet zaokruhlenych riadkov. Bez DB, bez float.
 */
final class InvoiceCalculator
{
    /**
     * @param list<array{allocation_id:int, task_id:int, title:string, task_ref:?string, portal_url:?string, estimated_hours:?string, hours:string, rate:string, amount:string, currency:string}> $parts
     *
     * @return array{lines: list<array<string, mixed>>, total: string, hours: string, line_count: int, currency: string}
     */
    public function compute(array $parts, ?string $vatCode): array
    {
        if ($parts === []) {
            throw new IssueRejected('no_parts');
        }

        $currencies = [];

        foreach ($parts as $part) {
            $currencies[trim($part['currency'])] = true;
        }

        if (count($currencies) > 1) {
            throw new IssueRejected('mixed_currency', ['currencies' => array_keys($currencies)]);
        }

        $lines   = [];
        $amounts = [];
        $hours   = '0.00';
        $pos     = 0;

        foreach ($parts as $part) {
            $amount = Money::of($part['rate'])->timesHours($part['hours']);

            if (! $amount->equals(Money::of($part['amount']))) {
                throw new IssueRejected('amount_mismatch', ['allocation_id' => $part['allocation_id']]);
            }

            $pos++;
            $amounts[] = $amount;
            $hours     = Hours::plus($hours, $part['hours']);
            $lines[]   = [
                'position'             => $pos,
                'source_allocation_id' => $part['allocation_id'],
                'source_task_id'       => $part['task_id'],
                'title'                => $part['title'],
                'task_ref'             => $part['task_ref'],
                'portal_url'           => $part['portal_url'],
                'estimated_hours'      => $part['estimated_hours'],
                'hours'                => Hours::plus($part['hours'], '0'),
                'rate'                 => Money::of($part['rate'])->toDecimalString(),
                'amount'               => $amount->toDecimalString(),
                'vat_code'             => $vatCode,
            ];
        }

        return [
            'lines'      => $lines,
            'total'      => Money::sum(...$amounts)->toDecimalString(),
            'hours'      => $hours,
            'line_count' => count($lines),
            'currency'   => (string) array_key_first($currencies),
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    public static function previewToken(int $clientId, string $month, int $entityId, array $lines): string
    {
        usort($lines, static fn (array $a, array $b): int => $a['source_allocation_id'] <=> $b['source_allocation_id']);

        $parts = array_map(
            static fn (array $l): string => $l['source_allocation_id'] . ':' . $l['hours'] . ':' . $l['rate'] . ':' . $l['amount'],
            $lines,
        );

        return hash('sha256', $clientId . '|' . $month . '|' . $entityId . '|' . implode(';', $parts));
    }
}
