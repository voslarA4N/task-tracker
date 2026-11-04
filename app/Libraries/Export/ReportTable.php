<?php

declare(strict_types=1);

namespace App\Libraries\Export;

use App\Libraries\Reporting\ReportResult;

/**
 * ReportResult do ExportTable: jedine miesto stlpcov exportu prehladu. Iba mapuje hotove sumy, nepocita.
 */
final class ReportTable
{
    public static function month(ReportResult $r, string $locale): ExportTable
    {
        $cur  = ExportCell::text($r->currency);
        $rows = [];

        foreach ($r->clients as $c) {
            $rows[] = [ExportCell::text($c['client_name']), ExportCell::money($c['invoiced']), ExportCell::money($c['paid']), $cur];
        }

        $rows[] = [ExportCell::text(lang('Export.total', [], $locale)), ExportCell::money($r->invoicedTotal), ExportCell::money($r->paidTotal), $cur];

        return new ExportTable(lang('Export.sheetMonth', [], $locale), self::headers($locale, false), $rows, [32, 16, 16, 8]);
    }

    public static function year(ReportResult $r, string $locale): ExportTable
    {
        $cur  = ExportCell::text($r->currency);
        $all  = ExportCell::text(lang('Export.allClients', [], $locale));
        $rows = [];

        foreach ($r->months as $m) {
            $rows[] = [ExportCell::text($m['month']), $all, ExportCell::money($m['invoiced']), ExportCell::money($m['paid']), $cur];
        }

        foreach ($r->clients as $c) {
            $rows[] = [ExportCell::text($r->period->key), ExportCell::text($c['client_name']), ExportCell::money($c['invoiced']), ExportCell::money($c['paid']), $cur];
        }

        $rows[] = [ExportCell::text($r->period->key), ExportCell::text(lang('Export.total', [], $locale)), ExportCell::money($r->invoicedTotal), ExportCell::money($r->paidTotal), $cur];

        return new ExportTable(lang('Export.sheetYear', [], $locale), self::headers($locale, true), $rows, [12, 32, 16, 16, 8]);
    }

    /**
     * @return list<string>
     */
    private static function headers(string $locale, bool $withPeriod): array
    {
        $h = [
            lang('Export.colClient', [], $locale),
            lang('Export.colInvoiced', [], $locale),
            lang('Export.colPaid', [], $locale),
            lang('Export.colCurrency', [], $locale),
        ];

        return $withPeriod ? [lang('Export.colPeriod', [], $locale), ...$h] : $h;
    }
}
