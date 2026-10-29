<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use App\Libraries\Invoicing\PdfFormat;

/**
 * Hodnoty placeholderov sablon e-mailov (D-15) z riadku faktury. Ziadne float.
 */
final class TemplateContext
{
    /**
     * @param array<string, mixed> $invoice riadok z invoices, snapshot dekodovany na pole, voliteľne `balance` z invoice_status_v (D-15)
     *
     * @return array<string, string>
     */
    public static function values(array $invoice, string $today): array
    {
        $lang     = (string) ($invoice['doc_language'] ?? 'sk');
        $currency = trim((string) $invoice['currency']);
        $total    = (string) $invoice['total'];
        $balance  = isset($invoice['balance']) ? (string) $invoice['balance'] : $total;
        $snapshot = is_array($invoice['snapshot'] ?? null) ? $invoice['snapshot'] : [];
        $month    = (string) ($invoice['billing_month'] ?? '');
        $iban     = (string) ($snapshot['bank']['iban'] ?? '');
        $due      = (string) ($invoice['due_date'] ?? '');

        return [
            'number'          => (string) ($invoice['number'] ?? ''),
            'total'           => PdfFormat::amount($total, $currency, $lang),
            'balance'         => PdfFormat::amount($balance, $currency, $lang),
            'currency'        => $currency,
            'issue_date'      => self::date($invoice['issue_date'] ?? null, $lang),
            'due_date'        => self::date($due, $lang),
            'billing_month'   => preg_match('/^(\d{4})-(\d{2})$/D', $month, $m) === 1 ? $m[2] . '/' . $m[1] : $month,
            'variable_symbol' => (string) ($snapshot['variable_symbol'] ?? ''),
            'iban'            => trim(implode(' ', str_split(str_replace(' ', '', $iban), 4))),
            'client_name'     => (string) ($snapshot['client']['name'] ?? ''),
            'entity_name'     => (string) ($snapshot['entity']['trade_name'] ?? ''),
            'days_overdue'    => (string) self::daysOverdue($due, $today),
        ];
    }

    private static function date(mixed $ymd, string $lang): string
    {
        return is_string($ymd) && $ymd !== '' ? PdfFormat::date($ymd, $lang) : '';
    }

    private static function daysOverdue(string $due, string $today): int
    {
        if ($due === '') {
            return 0;
        }

        $a = service('clock')->parseDate($due);
        $b = service('clock')->parseDate($today);

        if ($a === null || $b === null || $b <= $a) {
            return 0;
        }

        return (int) $a->diff($b)->days;
    }
}
