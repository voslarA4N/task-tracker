<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pregenerovanie dokladu bolo odmietnute z dôvodu v $reason (jeden z REASONS).
 */
final class RegenerateRejected extends RuntimeException
{
    public const REASONS = ['not_found', 'not_regenerable', 'window_expired', 'stale', 'render_failed'];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $context = [],
    ) {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unknown regenerate rejection reason: ' . $reason);
        }

        parent::__construct('Document regeneration rejected: ' . $reason);
    }
}
