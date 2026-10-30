<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use RuntimeException;

/**
 * Uprava nastavenia kurzu odmietnuta. $reason: invalid, stale, not_found.
 */
final class FxSettingsRejected extends RuntimeException
{
    /**
     * @param list<string> $fields kluce poli v tvare "PL.source" (iba pri reason invalid)
     */
    public function __construct(public readonly string $reason, public readonly array $fields = [])
    {
        parent::__construct('FX settings change rejected: ' . $reason);
    }
}
