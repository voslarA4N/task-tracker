<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use RuntimeException;

/**
 * Uprava zakonneho textu odmietnuta. $reason: empty, too_long, stale, not_found, exists, invalid.
 */
final class LegalTextRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Legal text change rejected: ' . $reason);
    }
}
