<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Invoicing\Qr\QrPaymentBuilder;
use App\Libraries\Invoicing\Qr\QrSvg;
use Composer\InstalledVersions;
use InvalidArgumentException;
use Mpdf\HTMLParserMode;
use Mpdf\Output\Destination;

/**
 * Sablona inv-2: PDF vznika vyhradne z kopie udajov (snapshot schema 2), tlaci iba polia z legal.print (D-03, D-04).
 */
final class MpdfInvoiceInv2Renderer implements PdfRenderer
{
    public const TEMPLATE_VERSION = 'inv-2';

    public function render(array $snapshot): RenderedPdf
    {
        $number = $snapshot['number'] ?? null;

        if (! is_string($number) || $number === '') {
            throw new InvalidArgumentException('Snapshot has no invoice number.');
        }

        $l    = $snapshot['labels'];
        $mpdf = MpdfFactory::create();
        $qr   = null;
        $pay  = (array) ($snapshot['payment'] ?? []);

        if (($pay['method'] ?? null) === 'transfer' && is_array($pay['qr'] ?? null)) {
            $standard = (string) $pay['qr']['standard'];
            $labelKey = ['pay_by_square' => 'qrPayBySquare', 'spayd' => 'qrSpayd', 'zbp' => 'qrZbp'][$standard] ?? null;
            $qr       = [
                'uri'      => QrSvg::dataUri((string) $pay['qr']['payload'], QrPaymentBuilder::ECC[$standard] ?? 'M'),
                'standard' => $labelKey === null ? '' : (string) $l[$labelKey],
                'caption'  => (string) $l['qrCaption'],
            ];
        }

        $mpdf->SetTitle($l['invoice'] . ' ' . $number);
        $mpdf->SetAuthor((string) $snapshot['entity']['trade_name']);
        $mpdf->SetCreator('Task Tracker ' . self::TEMPLATE_VERSION);
        $mpdf->SetHTMLFooter('<div class="footer">' . esc((string) $snapshot['entity']['trade_name']) . ' · ' . esc($number) . ' · ' . esc((string) $l['page']) . ' {PAGENO}/{nbpg}</div>');

        $mpdf->WriteHTML((string) file_get_contents(ROOTPATH . 'resources/pdf/invoice-inv2.css'), HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(view('pdf/invoice_inv2', ['s' => $snapshot, 'qr' => $qr]), HTMLParserMode::HTML_BODY);

        $bytes = $mpdf->Output('', Destination::STRING_RETURN);
        unset($mpdf);

        return RenderedPdf::fromBytes(
            $bytes,
            self::TEMPLATE_VERSION,
            'mpdf ' . ltrim((string) InstalledVersions::getPrettyVersion('mpdf/mpdf'), 'v'),
        );
    }
}
