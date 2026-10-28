<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Invoicing\PdfRenderer;
use App\Libraries\Invoicing\RenderedPdf;

/**
 * Rychly renderer pre testy suboehu (bez mPDF).
 */
final class FakePdfRenderer implements PdfRenderer
{
    public int $calls = 0;

    public function __construct(private readonly string $templateVersion = '')
    {
    }

    public function render(array $snapshot): RenderedPdf
    {
        $this->calls++;

        return RenderedPdf::fromBytes(
            '%PDF-FAKE ' . ($snapshot['number'] ?? 'null') . ' ' . ($snapshot['totals']['total'] ?? ''),
            $this->templateVersion !== '' ? $this->templateVersion : (string) $snapshot['template_version'],
            'fake',
        );
    }
}
