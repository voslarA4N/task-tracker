<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use InvalidArgumentException;
use RuntimeException;

/**
 * Uprava limitu alebo pociatocneho obratu odmietnuta. $reason: invalid, stale, not_found, duplicate.
 */
final class LimitRejected extends RuntimeException
{
    public const REASONS = ['invalid', 'stale', 'not_found', 'duplicate'];

    /**
     * @param array<string, string> $fields pole => lang kluc chyby
     */
    public function __construct(public readonly string $reason, public readonly array $fields = [])
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unknown rejection reason: ' . $reason);
        }

        parent::__construct('Limit change rejected: ' . $reason);
    }
}
