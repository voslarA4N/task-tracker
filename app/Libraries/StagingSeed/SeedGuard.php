<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed;

use CodeIgniter\Database\BaseConnection;

/**
 * Nezavisle poistky proti seedovaniu produkcie (D-01). Kazda kontrola stoji sama, poradie je dohodnute len kvoli hlaske.
 */
final class SeedGuard
{
    public const PRODUCTION_HOSTS = ['work-tracker.vostack.dev'];

    /**
     * Tabulky, ktore musia byt prazdne: produkcia v nich ma vzdy data.
     */
    public const TABLES = ['invoices', 'entities', 'clients', 'tasks', 'users'];

    /**
     * @param callable(): array<string, int> $counts pocty riadkov podla tabulky
     *
     * @throws SeedRefused
     */
    public static function check(bool $flag, string $baseUrl, callable $counts): void
    {
        if (! $flag) {
            throw new SeedRefused(SeedRefused::FLAG_OFF, 'staging.seed is not true');
        }

        $host = strtolower(rtrim((string) parse_url(trim($baseUrl), PHP_URL_HOST), '.'));

        if ($host === '' || in_array($host, self::PRODUCTION_HOSTS, true)) {
            throw new SeedRefused(SeedRefused::PRODUCTION_HOST, $host === '' ? 'baseURL has no host' : 'baseURL is the production host');
        }

        foreach ($counts() as $table => $count) {
            if ($count !== 0) {
                throw new SeedRefused(SeedRefused::NOT_PRISTINE, 'table ' . $table . ' is not empty');
            }
        }
    }

    /**
     * @param BaseConnection<mixed, mixed> $db
     *
     * @return callable(): array<string, int>
     */
    public static function countsFor(BaseConnection $db): callable
    {
        return static function () use ($db): array {
            $out = [];

            foreach (self::TABLES as $table) {
                $out[$table] = (int) $db->query('SELECT count(*) AS c FROM ' . $db->escapeIdentifiers($table))->getRow()->c;
            }

            return $out;
        };
    }
}
