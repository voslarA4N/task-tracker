<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use InvalidArgumentException;

/**
 * Vyber renderera podla template_version v kopii udajov (D-01): stare doklady sa renderuju svojou verziou.
 */
final class RendererRegistry
{
    /**
     * @param array<string, PdfRenderer> $overrides mapa templateVersion => renderer (testy)
     */
    public function __construct(private readonly array $overrides = [])
    {
    }

    public function for(string $templateVersion): PdfRenderer
    {
        return $this->overrides[$templateVersion] ?? match ($templateVersion) {
            'inv-1' => new MpdfInvoiceRenderer(),
            'inv-2' => new MpdfInvoiceInv2Renderer(),
            'rep-1' => new MpdfReportRenderer(),
            default => throw new InvalidArgumentException('Unknown template version: ' . $templateVersion),
        };
    }
}
