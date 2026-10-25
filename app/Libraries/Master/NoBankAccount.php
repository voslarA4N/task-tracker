<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use RuntimeException;

/**
 * Subjekt nemá žiadny aktívny bankový účet.
 */
final class NoBankAccount extends RuntimeException
{
}
