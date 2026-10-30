<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

interface RateProvider
{
    public function source(): string;

    /**
     * Mena subjektu, v ktorej zdroj publikuje (PLN, EUR, CZK).
     */
    public function homeCurrency(): string;

    /**
     * 'multiply' (suma * kurz) alebo 'divide' (suma / kurz).
     */
    public function direction(): string;

    /**
     * Posledny publikovany kurz s datumom platnosti <= $bound (Y-m-d).
     *
     * @throws RateNotFound
     * @throws RateUnavailable
     */
    public function latestOnOrBefore(string $currency, string $bound): RateQuote;
}
