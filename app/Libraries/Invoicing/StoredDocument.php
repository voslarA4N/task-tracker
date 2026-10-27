<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

final readonly class StoredDocument
{
    public function __construct(
        public string $bytes,
        public string $sha256,
        public int $byteSize,
        public string $templateVersion,
        public string $renderer,
    ) {
    }
}
