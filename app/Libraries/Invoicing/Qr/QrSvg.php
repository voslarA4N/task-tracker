<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing\Qr;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR ako vektorovy SVG data URI, lokalne (D-17).
 */
final class QrSvg
{
    public static function dataUri(string $payload, string $ecc = 'M'): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 2), new SvgImageBackEnd()));
        $svg    = $writer->writeString($payload, 'UTF-8', $ecc === 'L' ? ErrorCorrectionLevel::L() : ErrorCorrectionLevel::M());

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
