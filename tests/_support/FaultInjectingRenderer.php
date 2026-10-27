<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Invoicing\PdfRenderer;
use App\Libraries\Invoicing\RenderedPdf;
use RuntimeException;

/**
 * Simuluje zlyhanie renderu po pridelení čísla.
 */
final class FaultInjectingRenderer implements PdfRenderer
{
    public function render(array $snapshot): RenderedPdf
    {
        throw new RuntimeException('injected render failure');
    }
}
