<?php

declare(strict_types=1);

namespace App\Libraries\Work;

use RuntimeException;

/**
 * Fakturacna cast je na vystavenej faktúre a nemozno ju menit, presunut ani zmazat (HTTP 409).
 */
final class AllocationLocked extends RuntimeException
{
    public function __construct(public readonly int $allocationId)
    {
        parent::__construct('Allocation ' . $allocationId . ' is locked by an issued invoice');
    }
}
