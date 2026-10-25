<?php

declare(strict_types=1);

namespace App\Libraries\Master;

/**
 * Porovnanie verzií údajov subjektu (D-05). Iba EntityInput::VERSION_FIELDS, null a '' sú rovnaké.
 */
final class VersionDiff
{
    /**
     * @param array<string, mixed>|null $older
     * @param array<string, mixed>      $newer
     *
     * @return list<string>
     */
    public static function changedFields(?array $older, array $newer): array
    {
        if ($older === null) {
            return [];
        }

        $changed = [];

        foreach (EntityInput::VERSION_FIELDS as $field) {
            if (self::norm($older[$field] ?? null) !== self::norm($newer[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private static function norm(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
