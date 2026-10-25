<?php

declare(strict_types=1);

namespace App\Libraries\Work;

use RuntimeException;

/**
 * Ulohu nemozno zmazat, lebo ma zamknutu (vyfakturovanu) cast (D-03).
 */
final class TaskHasLockedAllocations extends RuntimeException
{
    public function __construct(public readonly int $taskId)
    {
        parent::__construct('Task ' . $taskId . ' has locked allocations');
    }
}
