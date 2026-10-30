<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use RuntimeException;

/**
 * Zdroj kurzu nepodporuje dvojicu mena subjektu a mena faktury (D-03, D-05). Hadze sa bez HTTP volania.
 */
final class UnsupportedPair extends RuntimeException
{
}
