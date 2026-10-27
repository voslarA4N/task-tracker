<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use Composer\InstalledVersions;
use InvalidArgumentException;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Sablona inv-1: PDF vznika vyhradne z kopie udajov (snapshot), nikdy zo zivych tabuliek ani nastaveni (D-17).
 */
final class MpdfInvoiceRenderer implements PdfRenderer
{
    public const TEMPLATE_VERSION = 'inv-1';

    public function render(array $snapshot): RenderedPdf
    {
        $number = $snapshot['number'] ?? null;

        if (! is_string($number) || $number === '') {
            throw new InvalidArgumentException('Snapshot has no invoice number.');
        }

        $tempDir = WRITEPATH . 'mpdf';

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode'             => 'utf-8',
            'format'           => 'A4',
            'tempDir'          => $tempDir,
            'fontDir'          => [ROOTPATH . 'resources/fonts'],
            'fontdata'         => ['dejavusans' => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf']],
            'default_font'     => 'dejavusans',
            'useSubstitutions' => false,
            'autoScriptToLang' => false,
            'autoLangToFont'   => false,
        ]);

        $mpdf->SetTitle($snapshot['labels']['invoice'] . ' ' . $number);
        $mpdf->SetAuthor((string) $snapshot['entity']['trade_name']);
        $mpdf->SetCreator('Task Tracker ' . self::TEMPLATE_VERSION);

        $mpdf->WriteHTML((string) file_get_contents(ROOTPATH . 'resources/pdf/invoice-inv1.css'), HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(view('pdf/invoice_inv1', ['s' => $snapshot]), HTMLParserMode::HTML_BODY);

        $bytes = $mpdf->Output('', Destination::STRING_RETURN);

        return RenderedPdf::fromBytes(
            $bytes,
            self::TEMPLATE_VERSION,
            'mpdf ' . ltrim((string) InstalledVersions::getPrettyVersion('mpdf/mpdf'), 'v'),
        );
    }
}
