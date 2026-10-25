<?php

declare(strict_types=1);

namespace App\Libraries\Platform;

use NumberFormatter;

/**
 * Iba zobrazenie, bez float: cele cislo cez NumberFormatter, desatinna cast a symbol meny zlozene
 * podla vzoru lokality (suffix `1 080,00 €` alebo prefix `€1,080.00`).
 */
final class MoneyFormat
{
    public static function format(string $amount, string $currency, ?string $locale = null): string
    {
        $locale ??= service('request')->getLocale();
        $money              = Money::of($amount)->toDecimalString();
        $minus              = str_starts_with($money, '-') ? '-' : '';
        [$whole, $fraction] = explode('.', ltrim($money, '-')) + [1 => '00'];

        $decimal = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $decimal->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $integer = $decimal->format((int) $whole);
        $number  = ($integer === false ? $whole : $integer) . $decimal->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL) . $fraction;

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);
        $symbol = $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        return $minus . (str_starts_with($formatter->getPattern(), '¤') ? $symbol . $number : $number . "\u{00A0}" . $symbol);
    }
}
