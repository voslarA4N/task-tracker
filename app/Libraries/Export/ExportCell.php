<?php

declare(strict_types=1);

namespace App\Libraries\Export;

use App\Libraries\Platform\Money;
use InvalidArgumentException;

/**
 * Typovana bunka exportu (D-07): text sa chrani pred vzorcom, money/decimal su cisla v zapise s bodkou.
 */
final readonly class ExportCell
{
    /**
     * @param 'decimal'|'money'|'text' $kind
     */
    private function __construct(public string $kind, public string $value)
    {
    }

    public static function text(?string $v): self
    {
        return new self('text', $v ?? '');
    }

    public static function money(string $amount): self
    {
        return new self('money', Money::of($amount)->toDecimalString());
    }

    public static function decimal(string $v): self
    {
        if (preg_match('/^-?\d{1,10}(\.\d{1,8})?$/D', $v) !== 1) {
            throw new InvalidArgumentException('Invalid decimal value.');
        }

        return new self('decimal', $v);
    }
}
