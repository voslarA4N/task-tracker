<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use Composer\InstalledVersions;
use InvalidArgumentException;
use Mpdf\HTMLParserMode;
use Mpdf\Output\Destination;

/**
 * Sablona rep-1: vykaz prace vznika vyhradne z kopie udajov faktury (D-04), sucty zo snapshot.totals (REP-02).
 */
final class MpdfReportRenderer implements PdfRenderer
{
    public const TEMPLATE_VERSION = 'rep-1';

    public function render(array $snapshot): RenderedPdf
    {
        $number = $snapshot['number'] ?? null;

        if (! is_string($number) || $number === '') {
            throw new InvalidArgumentException('Snapshot has no invoice number.');
        }

        $l    = $snapshot['labels']['report'];
        $mpdf = MpdfFactory::create();

        $mpdf->SetTitle($l['title'] . ' ' . $number);
        $mpdf->SetAuthor((string) $snapshot['entity']['trade_name']);
        $mpdf->SetCreator('Task Tracker ' . self::TEMPLATE_VERSION);
        $mpdf->SetHTMLFooter('<div class="footer">' . esc((string) $snapshot['entity']['trade_name']) . ' · ' . esc($number) . ' · ' . esc((string) $l['page']) . ' {PAGENO}/{nbpg}</div>');

        $mpdf->WriteHTML((string) file_get_contents(ROOTPATH . 'resources/pdf/report-rep1.css'), HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(view('pdf/report_rep1', ['s' => $snapshot]), HTMLParserMode::HTML_BODY);

        $bytes = $mpdf->Output('', Destination::STRING_RETURN);
        unset($mpdf);

        return RenderedPdf::fromBytes(
            $bytes,
            self::TEMPLATE_VERSION,
            'mpdf ' . ltrim((string) InstalledVersions::getPrettyVersion('mpdf/mpdf'), 'v'),
        );
    }
}
