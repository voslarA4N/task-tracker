<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use RuntimeException;

/**
 * Zmena cisleneho radu odmietnuta. $reason: same_as_other, locked, initial_out_of_range.
 */
final class SeriesRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Series change rejected: ' . $reason);
    }
}
