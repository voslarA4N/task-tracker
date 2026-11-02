<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use Composer\InstalledVersions;
use InvalidArgumentException;
use Mpdf\HTMLParserMode;
use Mpdf\Output\Destination;

/**
 * Sablona cn-1: PDF dobropisu vznika vyhradne z kopie udajov (snapshot schema 4), bez QR a bez vykazu (D-18).
 */
final class MpdfCreditNoteCn1Renderer implements PdfRenderer
{
    public const TEMPLATE_VERSION = 'cn-1';

    public function render(array $snapshot): RenderedPdf
    {
        $number = $snapshot['number'] ?? null;

        if (! is_string($number) || $number === '') {
            throw new InvalidArgumentException('Snapshot has no credit note number.');
        }

        if (! is_array($snapshot['correction'] ?? null)) {
            throw new InvalidArgumentException('Snapshot has no correction block.');
        }

        $l    = $snapshot['labels'];
        $mpdf = MpdfFactory::create();

        $mpdf->SetTitle($l['cnTitle'] . ' ' . $number);
        $mpdf->SetAuthor((string) $snapshot['entity']['trade_name']);
        $mpdf->SetCreator('Task Tracker ' . self::TEMPLATE_VERSION);
        $mpdf->SetHTMLFooter('<div class="footer">' . esc((string) $snapshot['entity']['trade_name']) . ' · ' . esc($number) . ' · ' . esc((string) $l['page']) . ' {PAGENO}/{nbpg}</div>');

        $mpdf->WriteHTML((string) file_get_contents(ROOTPATH . 'resources/pdf/credit-note-cn1.css'), HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(view('pdf/credit_note_cn1', ['s' => $snapshot]), HTMLParserMode::HTML_BODY);

        $bytes = $mpdf->Output('', Destination::STRING_RETURN);
        unset($mpdf);

        return RenderedPdf::fromBytes(
            $bytes,
            self::TEMPLATE_VERSION,
            'mpdf ' . ltrim((string) InstalledVersions::getPrettyVersion('mpdf/mpdf'), 'v'),
        );
    }
}
