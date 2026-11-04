<?php

declare(strict_types=1);

namespace App\Libraries\Export;

use RuntimeException;

/**
 * Balik sa nedá vytvorit: 'empty' | 'missing_pdf' (numbers) | 'too_large'.
 */
final class BundleRejected extends RuntimeException
{
    /**
     * @param list<string> $numbers cisla dokladov bez ulozeneho PDF
     */
    public function __construct(public readonly string $reason, public readonly array $numbers = [])
    {
        parent::__construct('Accountant bundle rejected: ' . $reason);
    }
}
