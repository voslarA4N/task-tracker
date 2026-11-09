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
    /**
     * @var array<string, NumberFormatter> cela cisla podla lokality
     */
    private static array $decimals = [];

    /**
     * @var array<string, array{symbol: string, prefix: bool}> symbol a poloha podla lokality a meny
     */
    private static array $currencies = [];

    public static function format(string $amount, string $currency, ?string $locale = null): string
    {
        $locale ??= service('request')->getLocale();
        $money              = Money::of($amount)->toDecimalString();
        $minus              = str_starts_with($money, '-') ? '-' : '';
        [$whole, $fraction] = explode('.', ltrim($money, '-')) + [1 => '00'];

        $decimal = self::$decimals[$locale] ?? self::decimal($locale);
        $integer = $decimal->format((int) $whole);
        $number  = ($integer === false ? $whole : $integer) . $decimal->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL) . $fraction;

        $info = self::currency($locale, $currency);

        return $minus . ($info['prefix'] ? $info['symbol'] . $number : $number . "\u{00A0}" . $info['symbol']);
    }

    /**
     * Samotny symbol meny podla lokality (pre input-group-text).
     */
    public static function symbol(string $currency, ?string $locale = null): string
    {
        return self::currency($locale ?? service('request')->getLocale(), $currency)['symbol'];
    }

    // Vytvorenie NumberFormatter (ICU) je drahe a vo vypise sa vola stovky krat na poziadavku, preto sa drzi
    // v pamati poziadavky (PHP-FPM ju po poziadavke zahodi).
    private static function decimal(string $locale): NumberFormatter
    {
        $decimal = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $decimal->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);

        return self::$decimals[$locale] = $decimal;
    }

    /**
     * @return array{symbol: string, prefix: bool}
     */
    private static function currency(string $locale, string $currency): array
    {
        $key = $locale . '|' . $currency;

        if (! isset(self::$currencies[$key])) {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
            $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);
            self::$currencies[$key] = [
                'symbol' => $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL),
                'prefix' => str_starts_with($formatter->getPattern(), '¤'),
            ];
        }

        return self::$currencies[$key];
    }
}
