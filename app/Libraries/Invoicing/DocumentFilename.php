<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use InvalidArgumentException;

/**
 * Jedine pravidlo nazvu PDF dokladu (D-07) pre stiahnutie aj prilohu e-mailu. Len ASCII [A-Za-z0-9._-].
 */
final class DocumentFilename
{
    private const PREFIXES = [
        'invoice' => ['sk' => 'Faktura_', 'cs' => 'Faktura_', 'pl' => 'Faktura_', 'en' => 'Invoice_'],
        'report'  => ['sk' => 'Vykaz_', 'cs' => 'Vykaz_', 'pl' => 'Wykaz_', 'en' => 'Report_'],
    ];

    public static function for(string $kind, string $docLanguage, string $number): string
    {
        if (! isset(self::PREFIXES[$kind])) {
            throw new InvalidArgumentException('Unknown document kind: ' . $kind);
        }

        $prefix = self::PREFIXES[$kind][$docLanguage] ?? self::PREFIXES[$kind]['sk'];

        return $prefix . preg_replace('/[^A-Za-z0-9._-]/', '_', $number) . '.pdf';
    }
}
