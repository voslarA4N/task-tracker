<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use InvalidArgumentException;

/**
 * Neplatny format cisla dokladu. $reason: no_year, two_years, no_sequence, two_sequences,
 * sequence_too_long, two_months, bad_char, too_long, empty, ambiguous_literal.
 */
final class InvalidSeriesFormat extends InvalidArgumentException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Invalid series format: ' . $reason);
    }
}
