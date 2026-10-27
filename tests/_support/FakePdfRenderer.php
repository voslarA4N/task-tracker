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

    public function render(array $snapshot): RenderedPdf
    {
        $this->calls++;

        return RenderedPdf::fromBytes(
            '%PDF-FAKE ' . ($snapshot['number'] ?? 'null') . ' ' . ($snapshot['totals']['total'] ?? ''),
            'inv-1',
            'fake',
        );
    }
}
