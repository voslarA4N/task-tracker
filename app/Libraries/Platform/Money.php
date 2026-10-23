<?php

declare(strict_types=1);

namespace App\Libraries\Platform;

use InvalidArgumentException;
use OverflowException;
use RoundingMode;
use Stringable;

/**
 * Immutable money value over a decimal string (numeric(12,2)), bcmath only.
 * Never use float. Every bc* call has an explicit scale.
 */
final class Money implements Stringable
{
    private const MAX = '9999999999.99';

    private function __construct(private readonly string $amount)
    {
    }

    public static function of(string $value): self
    {
        if (preg_match('/^-?\d{1,10}(\.\d{1,2})?$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid money value.');
        }

        return new self(self::norm(bcadd($value, '0', 2)));
    }

    public static function zero(): self
    {
        return new self('0.00');
    }

    public static function sum(self ...$items): self
    {
        $total = self::zero();

        foreach ($items as $item) {
            $total = $total->plus($item);
        }

        return $total;
    }

    public function timesHours(string $hours): self
    {
        if (preg_match('/^-?\d{1,5}(\.\d{1,2})?$/D', $hours) !== 1) {
            throw new InvalidArgumentException('Invalid hours value.');
        }

        $product = bcround(bcmul($this->amount, $hours, 4), 2, RoundingMode::HalfAwayFromZero);

        return self::checked($product);
    }

    public function plus(self $o): self
    {
        return self::checked(bcadd($this->amount, $o->amount, 2));
    }

    public function minus(self $o): self
    {
        return self::checked(bcsub($this->amount, $o->amount, 2));
    }

    public function negate(): self
    {
        return new self(self::norm(bcsub('0', $this->amount, 2)));
    }

    public function compare(self $o): int
    {
        return bccomp($this->amount, $o->amount, 2);
    }

    public function equals(self $o): bool
    {
        return $this->compare($o) === 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', 2) === 0;
    }

    public function toDecimalString(): string
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    private static function checked(string $value): self
    {
        $value = self::norm(bcadd($value, '0', 2));
        $abs   = ltrim($value, '-');

        if (bccomp($abs, self::MAX, 2) > 0) {
            throw new OverflowException('Money value out of numeric(12,2) range.');
        }

        return new self($value);
    }

    private static function norm(string $value): string
    {
        return $value === '-0.00' ? '0.00' : $value;
    }
}
