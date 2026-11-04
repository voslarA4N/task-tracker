<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use RuntimeException;

/**
 * Uprava nastavenia dokladov odmietnuta. $reason: invalid, stale, not_found.
 */
final class DocumentSettingsRejected extends RuntimeException
{
    /**
     * @param list<string> $fields kluce poli (iba pri reason invalid)
     */
    public function __construct(public readonly string $reason, public readonly array $fields = [])
    {
        parent::__construct('Document settings change rejected: ' . $reason);
    }
}
