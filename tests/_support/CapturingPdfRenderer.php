<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Invoicing\PdfRenderer;
use App\Libraries\Invoicing\RenderedPdf;

/**
 * Renderer zachytavajuci vstupny snapshot (D-10 test zhody).
 */
final class CapturingPdfRenderer implements PdfRenderer
{
    public int $calls = 0;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $lastSnapshot = null;

    public function __construct(private readonly string $templateVersion)
    {
    }

    public function render(array $snapshot): RenderedPdf
    {
        $this->calls++;
        $this->lastSnapshot = $snapshot;

        return RenderedPdf::fromBytes('%PDF-CAPTURE ' . $this->calls . ' ' . ($snapshot['number'] ?? ''), $this->templateVersion, 'capture');
    }
}
