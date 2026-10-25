<?php

declare(strict_types=1);

namespace App\Libraries\Work\Import;

final readonly class ImportSheet
{
    /**
     * @param list<ImportBlock> $blocks
     */
    public function __construct(
        public ?string $rate,
        public array $blocks,
    ) {
    }
}
