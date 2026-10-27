<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use Mpdf\Mpdf;

/**
 * Jedna konfiguracia mPDF pre sablony inv-2 a dalsie (D-22): jeden font, bez substitucii, okraje 15/15/15/18 mm.
 * Kazdy render dostane vlastny objekt Mpdf. Sablona inv-1 si drzi vlastnu konfiguraciu a tu sa nemeni (D-01).
 */
final class MpdfFactory
{
    public static function create(): Mpdf
    {
        $tempDir = WRITEPATH . 'mpdf';

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        return new Mpdf([
            'mode'             => 'utf-8',
            'format'           => 'A4',
            'tempDir'          => $tempDir,
            'fontDir'          => [ROOTPATH . 'resources/fonts'],
            'fontdata'         => ['dejavusans' => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf']],
            'default_font'     => 'dejavusans',
            'useSubstitutions' => false,
            'autoScriptToLang' => false,
            'autoLangToFont'   => false,
            'margin_left'      => 15,
            'margin_right'     => 15,
            'margin_top'       => 15,
            'margin_bottom'    => 18,
        ]);
    }
}
