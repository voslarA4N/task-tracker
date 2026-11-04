<?php

declare(strict_types=1);

namespace App\Libraries\Export;

use App\Libraries\Invoicing\DocumentFilename;

/**
 * Zoznam dokladov do ExportTable (D-12), spolocny pre balik a export zoznamu faktur. Iba mapuje hotove hodnoty.
 */
final class DocumentListExport
{
    /**
     * @param list<array<string, mixed>> $docs
     */
    public static function table(array $docs, bool $withFile): ExportTable
    {
        $headers = [
            lang('Export.colNumber'),
            lang('Export.colType'),
            lang('Export.colClient'),
            lang('Export.colIssueDate'),
            lang('Export.colTurnoverDate'),
            lang('Export.colDueDate'),
            lang('Export.colCurrency'),
            lang('Export.colDocAmount'),
            lang('Export.colRate'),
            lang('Export.colEntityAmount'),
            lang('Export.colPaymentStatus'),
            lang('Export.colPaidOn'),
        ];
        $widths = [18, 14, 28, 14, 14, 14, 8, 18, 12, 20, 20, 14];

        if ($withFile) {
            $headers[] = lang('Export.colFile');
            $widths[]  = 34;
        }

        $rows = [];

        foreach ($docs as $d) {
            $rows[] = self::row($d, $withFile);
        }

        return new ExportTable(lang('Export.sheetDocuments'), $headers, $rows, $widths);
    }

    /**
     * @param array<string, mixed> $doc
     */
    public static function pdfName(array $doc): string
    {
        return DocumentFilename::for((string) $doc['type'] === 'credit_note' ? 'credit_note' : 'invoice', (string) $doc['doc_language'], (string) $doc['number']);
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return list<ExportCell>
     */
    private static function row(array $d, bool $withFile): array
    {
        $external = (string) $d['origin'] === 'external';
        $isCn     = (string) $d['type'] === 'credit_note';
        $status   = (string) $d['status'];

        $number = $external
            ? ((string) ($d['external_ref'] ?? '') !== '' ? (string) $d['external_ref'] : lang('Invoices.externalTitle'))
            : (string) ($d['number'] ?? '');

        $type = $external ? lang('Export.typeExternal') : lang($isCn ? 'Export.typeCreditNote' : 'Export.typeInvoice');

        $paidOn = ($status === 'partially_paid' || $status === 'paid') && ($d['last_paid_on'] ?? null) !== null ? (string) $d['last_paid_on'] : '';

        $rate = ! $external && ($d['fx_rate'] ?? null) !== null && (string) $d['fx_rate'] !== '' ? ExportCell::decimal(self::trimRate((string) $d['fx_rate'])) : ExportCell::text('');

        $entityAmount = ! $external && ($d['total_entity_ccy'] ?? null) !== null ? ExportCell::money((string) $d['total_entity_ccy']) : ExportCell::text('');

        $row = [
            ExportCell::text($number),
            ExportCell::text($type),
            ExportCell::text((string) $d['client_name']),
            ExportCell::text((string) ($d['issue_date'] ?? '')),
            ExportCell::text((string) ($d['turnover_date'] ?? '')),
            ExportCell::text((string) ($d['due_date'] ?? '')),
            ExportCell::text((string) $d['currency']),
            ExportCell::money((string) $d['total']),
            $rate,
            $entityAmount,
            ExportCell::text(self::statusLabel($status, $isCn, $external)),
            ExportCell::text($paidOn),
        ];

        if ($withFile) {
            $row[] = ExportCell::text($external ? '' : self::pdfName($d));
        }

        return $row;
    }

    private static function statusLabel(string $status, bool $isCn, bool $external): string
    {
        if ($external) {
            return lang('Invoices.badgeExternal');
        }

        return match (true) {
            $isCn                        => lang($status === 'sent' ? 'Invoices.statusCnSent' : 'Invoices.statusCnIssued'),
            $status === 'cancelled'      => lang('Invoices.statusCancelled'),
            $status === 'sent'           => lang('Invoices.statusSent'),
            $status === 'partially_paid' => lang('Invoices.statusPartiallyPaid'),
            $status === 'paid'           => lang('Invoices.statusPaid'),
            default                      => lang('Invoices.statusIssued'),
        };
    }

    private static function trimRate(string $rate): string
    {
        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }
}
