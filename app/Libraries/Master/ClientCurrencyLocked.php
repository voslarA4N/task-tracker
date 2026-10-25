<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use RuntimeException;

/**
 * Menu klienta nemozno zmenit, kym ma klient akukolvek fakturacnu cast.
 */
final class ClientCurrencyLocked extends RuntimeException
{
    public function __construct(public readonly int $clientId)
    {
        parent::__construct('Client ' . $clientId . ' currency is locked by billing allocations');
    }
}
