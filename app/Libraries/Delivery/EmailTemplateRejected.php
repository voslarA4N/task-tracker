<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use RuntimeException;

/**
 * Uprava sablony e-mailu odmietnuta. $reason: empty, too_long, invalid, unknown_placeholder, stale, not_found.
 */
final class EmailTemplateRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $detail = null)
    {
        parent::__construct('E-mail template change rejected: ' . $reason);
    }
}
