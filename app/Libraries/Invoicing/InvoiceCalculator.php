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
     * Token nahladu: sha256 nad kanonickym JSON celej kopie udajov (datumy, verzia subjektu, banka,
     * pravne texty, hlavicka klienta, riadky v poradi). Vynechane su presne dva kluce, ktore su pred
     * vystavenim neznáme: `number` a `variable_symbol`. Vsetky ostatne polia kopie pochadzaju z DB
     * riadkov, vstupnych datumov alebo konstant, takze nie je v nej ziadna volatilna hodnota.
     * Vstupom musi byt nezmeneny vystup `SnapshotBuilder::build()`. Poradie klucov je poradie
     * buildera a poradie riadkov je poradie z DB, token teda stazi aj poradie.
     *
     * @param array<string, mixed> $snapshot
     */
    public static function previewToken(array $snapshot): string
    {
        unset($snapshot['number'], $snapshot['variable_symbol']);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
