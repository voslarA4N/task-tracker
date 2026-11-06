<?php

declare(strict_types=1);

namespace App\Libraries\Platform;

use IntlDateFormatter;

/**
 * Datum pre UI podla jazyka rozhrania (ICU MEDIUM). Doklady maju vlastny format v PdfFormat.
 */
final class DateFormat
{
    /**
     * @param string $iso datum `Y-m-d`; neplatny vstup sa vrati bez zmeny
     */
    public static function date(string $iso, ?string $locale = null): string
    {
        $d = service('clock')->parseDate($iso);

        if ($d === null) {
            return $iso;
        }

        $formatter = new IntlDateFormatter($locale ?? service('request')->getLocale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, $d->getTimezone());
        $out       = $formatter->format($d);

        return $out === false ? $iso : $out;
    }
}
