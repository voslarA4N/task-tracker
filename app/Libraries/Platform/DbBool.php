<?php

declare(strict_types=1);

namespace App\Libraries\Platform;

/**
 * PG boolean prichadza z pdo/pgsql ako true, 't' alebo 'true' podla ovladaca.
 */
final class DbBool
{
    public static function of(mixed $v): bool
    {
        return in_array($v, [true, 't', 'true', '1', 1], true);
    }

    public static function toDb(bool $v): string
    {
        return $v ? 'true' : 'false';
    }
}
