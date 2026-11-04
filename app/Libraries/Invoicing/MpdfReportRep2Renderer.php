<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use Composer\InstalledVersions;
use InvalidArgumentException;
use Mpdf\HTMLParserMode;
use Mpdf\Output\Destination;

/**
 * Sablona rep-2: ako rep-1, nazov ulohy a odkaz do portalu su v jednom stlpci pod sebou, odkaz smeruje priamo na URL z kopie udajov.
 */
final class MpdfReportRep2Renderer implements PdfRenderer
{
    public const TEMPLATE_VERSION = 'rep-2';

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
        if (($snapshot['sample'] ?? false) === true) {
            $mpdf->SetHTMLHeader('<div style="text-align: right; font-size: 12pt; font-weight: bold; color: #555555;">VZOR</div>');
        }

        $mpdf->SetHTMLFooter('<div class="footer">' . esc((string) $snapshot['entity']['trade_name']) . ' · ' . esc($number) . ' · ' . esc((string) $l['page']) . ' {PAGENO}/{nbpg}</div>');

        $mpdf->WriteHTML((string) file_get_contents(ROOTPATH . 'resources/pdf/report-rep2.css'), HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(view('pdf/report_rep2', ['s' => $snapshot]), HTMLParserMode::HTML_BODY);

        $bytes = $mpdf->Output('', Destination::STRING_RETURN);
        unset($mpdf);

        return RenderedPdf::fromBytes(
            $bytes,
            self::TEMPLATE_VERSION,
            'mpdf ' . ltrim((string) InstalledVersions::getPrettyVersion('mpdf/mpdf'), 'v'),
        );
    }
}
