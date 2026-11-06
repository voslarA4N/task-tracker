<?php

declare(strict_types=1);

namespace App\Libraries\Export;

/**
 * Vysledok rocneho archivu (D-10).
 */
final readonly class YearArchiveResult
{
    public function __construct(
        public string $path,
        public int $documents,
        public int $pdfs,
        public int $bytes,
        public string $sha256,
    ) {
    }
}
