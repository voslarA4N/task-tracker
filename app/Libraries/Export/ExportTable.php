<?php

declare(strict_types=1);

namespace App\Libraries\Export;

/**
 * Format-neutralna tabulka exportu (CSV, XLSX).
 */
final readonly class ExportTable
{
    /**
     * @param list<string>           $headers
     * @param list<list<ExportCell>> $rows
     * @param list<int>              $widths  sirky stlpcov pre XLSX v znakoch
     */
    public function __construct(
        public string $sheetName,
        public array $headers,
        public array $rows,
        public array $widths = [],
    ) {
    }
}
