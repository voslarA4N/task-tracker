<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use InvalidArgumentException;

/**
 * Register zdrojov kurzov. HOME je datovy fakt o API (mena, v ktorej zdroj publikuje), nie nastavenie.
 */
final class RateProviders
{
    public const HOME = ['NBP' => 'PLN', 'ECB' => 'EUR', 'CNB' => 'CZK'];

    public static function for(string $source, RateHttpClient $http): RateProvider
    {
        return match ($source) {
            'NBP'   => new NbpRateProvider($http),
            'ECB'   => new EcbRateProvider($http),
            'CNB'   => new CnbRateProvider($http),
            default => throw new InvalidArgumentException('Unknown rate source.'),
        };
    }
}
