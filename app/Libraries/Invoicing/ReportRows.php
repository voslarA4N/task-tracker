<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

/**
 * Hotovy obsah vykazu (retazce v jazyku dokladu) odvodeny z riadkov faktury. Bez DB, bez float.
 */
final class ReportRows
{
    public const LABEL_KEYS = ['title', 'period', 'invoiceRef', 'portal', 'task', 'estimated', 'billed', 'rate', 'amount', 'total', 'hoursOf', 'hoursOnly', 'rateLine', 'vatNoteHeading', 'page', 'month1', 'month2', 'month3', 'month4', 'month5', 'month6', 'month7', 'month8', 'month9', 'month10', 'month11', 'month12'];
    private const LABEL_MAX = 40;

    /**
     * @return array<string, string>
     */
    public static function labels(string $language): array
    {
        $labels = [];

        foreach (self::LABEL_KEYS as $key) {
            $labels[$key] = lang('ReportPdf.' . $key, [], $language);
        }

        return $labels;
    }

    /**
     * @param array<string, string> $reportLabels
     */
    public static function period(array $reportLabels, string $month): string
    {
        return $reportLabels['month' . (int) substr($month, 5, 2)] . ' ' . substr($month, 0, 4);
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, string>      $reportLabels
     *
     * @return array{rows: list<array{position:int, portal_label:?string, portal_url:?string, estimated:?string, billed:string}>, uniform_rate: ?string}
     */
    public static function build(array $lines, array $reportLabels, string $language): array
    {
        $rows  = [];
        $rates = [];

        foreach ($lines as $line) {
            $hours = PdfFormat::hours((string) $line['hours'], $language);
            $est   = $line['estimated_hours'] ?? null;

            $billed = $est === null
                ? str_replace('{0}', $hours, $reportLabels['hoursOnly'])
                : str_replace(['{0}', '{1}'], [$hours, PdfFormat::hours((string) $est, $language)], $reportLabels['hoursOf']);

            $url   = isset($line['portal_url']) ? (string) $line['portal_url'] : '';
            $isUrl = preg_match('~^https?://~i', $url) === 1;
            $ref   = isset($line['task_ref']) ? (string) $line['task_ref'] : '';

            if ($ref !== '') {
                $label = $ref;
            } elseif ($url !== '') {
                $bare  = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $url);
                $label = mb_strlen($bare) > self::LABEL_MAX ? mb_substr($bare, 0, self::LABEL_MAX) . '…' : $bare;
            } else {
                $label = null;
            }

            $rates[(string) $line['rate']] = true;
            $rows[]                        = [
                'position'     => (int) $line['position'],
                'portal_label' => $label,
                'portal_url'   => $isUrl ? $url : null,
                'estimated'    => $est === null ? null : PdfFormat::hours((string) $est, $language) . ' ' . self::unit($reportLabels),
                'billed'       => $billed,
            ];
        }

        return ['rows' => $rows, 'uniform_rate' => count($rates) === 1 ? (string) array_key_first($rates) : null];
    }

    /**
     * @param array<string, string> $reportLabels
     */
    private static function unit(array $reportLabels): string
    {
        return trim(str_replace('{0}', '', $reportLabels['hoursOnly']));
    }
}
