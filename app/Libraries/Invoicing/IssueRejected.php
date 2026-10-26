<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use RuntimeException;

/**
 * Vystavenie alebo vypocet faktury bolo odmietnute z dovodu v $reason (jeden z REASONS).
 */
final class IssueRejected extends RuntimeException
{
    public const REASONS = [
        'no_parts',
        'mixed_currency',
        'amount_mismatch',
        'parts_changed',
        'preview_stale',
        'already_issued',
        'currency_mismatch',
        'entity_inactive',
        'backdate_unconfirmed',
        'sequence_overflow',
        'legal_text_missing',
        'vat_code_missing',
        'no_bank_account',
        'version_not_found',
        'invalid_series_format',
        'not_found',
    ];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $context = [],
        public readonly ?int $invoiceId = null,
        public readonly ?string $invoiceNumber = null,
    ) {
        parent::__construct('Invoice issue rejected: ' . $reason);
    }
}
