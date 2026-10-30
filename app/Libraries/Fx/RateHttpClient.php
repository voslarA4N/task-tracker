<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

/**
 * Jediny okraj so sietou pre kurzove API (v testoch nahradeny FakeRateHttpClient).
 */
interface RateHttpClient
{
    /**
     * @throws RateUnavailable pri sietovej chybe, timeoute alebo DNS
     */
    public function get(string $url): HttpResult;
}
