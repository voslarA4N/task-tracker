<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use InvalidArgumentException;
use RuntimeException;

/**
 * Vystavenie alebo nahlad dobropisu bol odmietnuty z dovodu v $reason (jeden z REASONS).
 */
final class CreditNoteRejected extends RuntimeException
{
    public const REASONS = [
        'not_found',
        'not_creditable',
        'already_credited',
        'already_issued',
        'preview_stale',
        'date_before_original',
        'issue_future',
        'backdate_unconfirmed',
        'invalid_series_format',
        'sequence_overflow',
        'number_collision',
        'sum_mismatch',
        'parts_mismatch',
    ];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $context = [],
        public readonly ?int $creditNoteId = null,
        public readonly ?string $creditNoteNumber = null,
    ) {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unknown credit note rejection reason: ' . $reason);
        }

        parent::__construct('Credit note rejected: ' . $reason);
    }
}
