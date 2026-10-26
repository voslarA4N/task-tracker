<?php

declare(strict_types=1);

namespace App\Libraries\Work\Import;

/**
 * Vysledok ostreho behu importu.
 */
final readonly class ImportResult
{
    /**
     * @param list<string> $createdKeys
     */
    public function __construct(
        public int $created,
        public int $skipped,
        public int $parts,
        public array $createdKeys,
    ) {
    }
}
