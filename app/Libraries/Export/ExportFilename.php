<?php

declare(strict_types=1);

namespace App\Libraries\Export;

use InvalidArgumentException;

/**
 * Bezpecne nazvy exportovanych suborov (D-08): iba [a-z0-9-] a povolene pripony.
 */
final class ExportFilename
{
    public static function slug(string $name): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
        $slug  = trim((string) preg_replace('/[^a-z0-9]+/', '-', is_string($ascii) ? $ascii : ''), '-');
        $slug  = trim(substr($slug, 0, 40), '-');

        return $slug === '' ? 'subjekt' : $slug;
    }

    /**
     * @param list<string> $parts
     */
    public static function make(string $prefix, array $parts, string $ext): string
    {
        if (! in_array($ext, ['csv', 'xlsx', 'zip'], true)) {
            throw new InvalidArgumentException('Unsupported extension.');
        }

        $segments = [self::slug($prefix)];

        foreach ($parts as $p) {
            $segments[] = self::slug($p);
        }

        return implode('-', $segments) . '.' . $ext;
    }
}
