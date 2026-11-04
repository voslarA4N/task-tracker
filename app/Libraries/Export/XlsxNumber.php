<?php

declare(strict_types=1);

namespace App\Libraries\Export;

use App\Libraries\Platform\Money;
use InvalidArgumentException;

/**
 * Jedine miesto s (float) castom: NumericCell v OpenSpout vyzaduje int|float.
 * Format sa overi pred pretypovanim, v DB ani v suctoch sa float nepouziva.
 */
final class XlsxNumber
{
    public static function fromCell(ExportCell $c): float
    {
        if ($c->kind === 'money') {
            return (float) Money::of($c->value)->toDecimalString();
        }

        if ($c->kind === 'decimal' && preg_match('/^-?\d{1,10}(\.\d{1,8})?$/D', $c->value) === 1) {
            return (float) $c->value;
        }

        throw new InvalidArgumentException('Only money and decimal cells can be numeric.');
    }
}
