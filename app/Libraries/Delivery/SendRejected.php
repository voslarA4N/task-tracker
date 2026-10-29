<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use RuntimeException;

/**
 * Odoslanie bolo odmietnute z dovodu v $reason (not_found, external, not_sendable, no_recipients,
 * too_many_recipients, invalid_address, empty_content, too_long, attachments_failed, smtp_failed,
 * record_failed, sender_invalid). $field je 'to' alebo 'cc' pri invalid_address.
 */
final class SendRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $field = null,
    ) {
        parent::__construct('Invoice send rejected: ' . $reason);
    }
}
