<?php

declare(strict_types=1);

namespace App\Libraries\Work\Import;

/**
 * Jeden riadok ulohy zo zdrojoveho Excelu (normalizovane hodnoty, bez PhpSpreadsheet).
 */
final readonly class ImportRow
{
    public function __construct(
        public int $rowNumber,
        public string $month,
        public ?string $url,
        public bool $specificLink,
        public string $title,
        public string $hours,
        public ?string $excelAmount,
        public bool $done,
        public bool $billed,
    ) {
    }
}
