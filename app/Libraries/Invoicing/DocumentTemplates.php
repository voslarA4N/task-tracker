<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use InvalidArgumentException;

/**
 * Jediné miesto aktuálnych šablón dokladov. Vystavenie aj pregenerovanie
 * berú šablónu odtiaľto, nikdy zo snapshotu. Pri zavedení `rep-3` sa mení
 * iba tu a v RendererRegistry.
 */
final class DocumentTemplates
{
    public const INVOICE     = 'inv-2';
    public const REPORT      = 'rep-2';
    public const CREDIT_NOTE = 'cn-1';

    public static function current(string $kind, string $type): string
    {
        return match ([$kind, $type]) {
            ['invoice', 'invoice']     => self::INVOICE,
            ['invoice', 'credit_note'] => self::CREDIT_NOTE,
            ['report', 'invoice']      => self::REPORT,
            default                    => throw new InvalidArgumentException('No current template for ' . $kind . '/' . $type),
        };
    }
}
