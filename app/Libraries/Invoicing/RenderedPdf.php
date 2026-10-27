<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

final readonly class RenderedPdf
{
    private function __construct(
        public string $bytes,
        public string $sha256,
        public string $templateVersion,
        public string $renderer,
    ) {
    }

    public static function fromBytes(string $bytes, string $templateVersion, string $renderer): self
    {
        return new self($bytes, hash('sha256', $bytes), $templateVersion, $renderer);
    }
}
