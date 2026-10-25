<?php

declare(strict_types=1);

namespace App\Libraries\Work;

use RuntimeException;

/**
 * Zapis ulohy odmietnuty. $reason: client_unavailable, client_locked.
 */
final class TaskRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Task change rejected: ' . $reason);
    }
}
