<?php

declare(strict_types=1);

namespace App\Libraries\Export;

/**
 * Ochrana pred vzorcom v tabulkovom procesore (CSV injection, D-07).
 */
final class FormulaGuard
{
    public static function text(string $v): string
    {
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
            return "'" . $v;
        }

        return $v;
    }
}
