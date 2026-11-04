<?php

declare(strict_types=1);

namespace App\Libraries\Audit;

/**
 * Porovnanie pred/po pre detail denníka zmien (D-13). Čistá funkcia bez zápisu.
 */
final class AuditDiff
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     *
     * @return list<array{key: string, before: ?string, after: ?string, changed: bool}>
     */
    public static function fields(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];
        $keys = array_values(array_unique([...array_map('strval', array_keys($before)), ...array_map('strval', array_keys($after))]));
        $out  = [];

        foreach ($keys as $key) {
            $b = array_key_exists($key, $before) ? self::text($before[$key]) : null;
            $a = array_key_exists($key, $after) ? self::text($after[$key]) : null;

            $out[] = ['key' => $key, 'before' => $b, 'after' => $a, 'changed' => $b !== $a];
        }

        return $out;
    }

    private static function text(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }

        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        if (is_scalar($v)) {
            return (string) $v;
        }

        $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '' : $json;
    }
}
