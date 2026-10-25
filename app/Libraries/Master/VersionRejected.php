<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use RuntimeException;

final class VersionRejected extends RuntimeException
{
    private function __construct(public readonly string $reason)
    {
        parent::__construct('Entity version rejected: ' . $reason);
    }

    public static function duplicateDate(): self
    {
        return new self('duplicate_date');
    }

    public static function noChange(): self
    {
        return new self('no_change');
    }

    public static function outOfRange(): self
    {
        return new self('out_of_range');
    }
}
