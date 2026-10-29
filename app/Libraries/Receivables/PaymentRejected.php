<?php

declare(strict_types=1);

namespace App\Libraries\Receivables;

use RuntimeException;

/**
 * Zapis alebo storno uhrady bol odmietnuty z dovodu v $reason (not_found, not_payable, invalid_amount, non_positive,
 * overpaid, already_paid, invalid_date, future_date, note_too_long, empty_reason, already_voided).
 * $balance nesie aktualny zostatok faktury (retazec s 2 desatinnymi miestami) pri overpaid a already_paid.
 */
final class PaymentRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $balance = null,
    ) {
        parent::__construct('Payment rejected: ' . $reason);
    }
}
