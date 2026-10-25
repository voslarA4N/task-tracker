<?php

declare(strict_types=1);

namespace App\Libraries\Master;

enum Currency: string
{
    case EUR = 'EUR';
    case PLN = 'PLN';
    case CZK = 'CZK';
}
