<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use InvalidArgumentException;

/**
 * SQL fragment pre nazov klienta v citacich vystavenych dokladov (D-01): najprv nazov z nemenneho snapshotu,
 * ak chyba (externa/importovana faktura) alebo je prazdny, zivy clients.name. Aliasy su z whitelistu,
 * nikdy sa nestavaju zo vstupu poziadavky. Prijemcovia e-mailov ostavaju zivi (D-04).
 */
final class ClientNameSql
{
    private const INVOICE_ALIASES = ['i', 'f'];
    private const CLIENT_ALIASES  = ['c'];

    public static function snapshotName(string $invoiceAlias = 'i', string $clientAlias = 'c'): string
    {
        if (! in_array($invoiceAlias, self::INVOICE_ALIASES, true) || ! in_array($clientAlias, self::CLIENT_ALIASES, true)) {
            throw new InvalidArgumentException('alias');
        }

        return "COALESCE(NULLIF({$invoiceAlias}.snapshot->'client'->>'name', ''), {$clientAlias}.name)";
    }
}
