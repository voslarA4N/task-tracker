<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

interface PdfRenderer
{
    /**
     * @param array<string, mixed> $snapshot kopia udajov faktury (schema 1) s pridelenym cislom
     */
    public function render(array $snapshot): RenderedPdf;
}
