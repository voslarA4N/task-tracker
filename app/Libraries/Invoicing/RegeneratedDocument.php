<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

final readonly class RegeneratedDocument
{
    public function __construct(
        public int $version,
        public string $templateVersion,
        public string $sha256,
        public int $previousVersion,
        public string $previousTemplateVersion,
    ) {
    }
}
