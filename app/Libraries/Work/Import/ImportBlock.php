<?php

declare(strict_types=1);

namespace App\Libraries\Work\Import;

/**
 * Mesacny blok zdrojoveho hárku: riadky uloh a bunky suctu (stlpce C az F).
 */
final readonly class ImportBlock
{
    /**
     * @param list<ImportRow>                                                $rows
     * @param array<string, array{value: ?string, formula: ?string}>        $sums
     */
    public function __construct(
        public string $month,
        public int $firstRow,
        public int $lastRow,
        public array $rows,
        public array $sums,
    ) {
    }
}
