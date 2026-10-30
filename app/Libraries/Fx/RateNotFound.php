<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use RuntimeException;

/**
 * Zdroj odpovedal, ale pre menu a rozsah nepublikuje ziadny kurz (D-12).
 */
final class RateNotFound extends RuntimeException
{
}
