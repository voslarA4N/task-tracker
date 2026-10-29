<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use RuntimeException;

/**
 * Adresa odosielatela je neplatna (reason 'invalid') alebo mimo povolenych domen (reason 'domain').
 */
final class SenderRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Sender rejected: ' . $reason);
    }
}
