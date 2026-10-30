<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use App\Libraries\Platform\Money;
use InvalidArgumentException;
use OverflowException;
use RoundingMode;

/**
 * Jedine miesto prepoctu mien (D-10).
 *
 * Pravidlo: sucet faktury, jeden kurz, half-up raz na 2 desatinne; delenie truncuje na 12 desatinnych
 * pred zaokruhlenim; zmena pravidla po vyjadreni uctovnicky = iba tato funkcia.
 * NBP a CNB nasobia (total * rate / amount), ECB deli (total * amount / rate).
 */
final class FxConverter
{
    public const DEVIATION_PERCENT = '10';
    private const MAX              = '9999999999.99';

    public static function convert(string $total, string $rate, int $amount, string $direction): string
    {
        $total = Money::of($total)->toDecimalString();
        self::assertRate($rate, $amount, $direction);

        $raw = $direction === 'multiply'
            ? bcdiv(bcmul($total, $rate, 10), (string) $amount, 12)
            : bcdiv(bcmul($total, (string) $amount, 2), $rate, 12);

        $rounded = bcround($raw, 2, RoundingMode::HalfAwayFromZero);

        if (bccomp(ltrim($rounded, '-'), self::MAX, 2) > 0) {
            throw new OverflowException('Converted value out of numeric(12,2) range.');
        }

        return Money::of($rounded)->toDecimalString();
    }

    /**
     * Ci sa rucny kurz lisi od stiahnuteho o viac nez $maxPercent percent (WR-03). Porovnava sa kurz za 1 jednotku
     * (rate / amount) v rovnakom smere, aky publikuje zdroj. Zachyti preklep (42512 namiesto 4.2512) aj opacny smer.
     */
    public static function deviationExceeds(string $manual, int $manualAmount, string $fetched, int $fetchedAmount, string $maxPercent = self::DEVIATION_PERCENT): bool
    {
        self::assertRate($manual, $manualAmount, 'multiply');
        self::assertRate($fetched, $fetchedAmount, 'multiply');

        $m    = bcdiv($manual, (string) $manualAmount, 12);
        $f    = bcdiv($fetched, (string) $fetchedAmount, 12);
        $diff = bcsub($m, $f, 12);

        return bccomp(bcmul(ltrim($diff, '-'), '100', 12), bcmul($f, $maxPercent, 12), 12) > 0;
    }

    /**
     * Spolocna validacia kurzu (T-08-05).
     */
    public static function assertRate(string $rate, int $amount, string $direction): void
    {
        if (preg_match('/^\d{1,10}(\.\d{1,8})?$/D', $rate) !== 1 || bccomp($rate, '0', 8) <= 0) {
            throw new InvalidArgumentException('Invalid rate.');
        }

        if ($amount < 1) {
            throw new InvalidArgumentException('Invalid amount.');
        }

        if (! in_array($direction, ['multiply', 'divide'], true)) {
            throw new InvalidArgumentException('Invalid direction.');
        }
    }
}
