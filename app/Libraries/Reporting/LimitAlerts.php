<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

/**
 * Vyber a zoradenie upozorneni na limity obratu pre dashboard (D-15): iba s dovodom.
 */
final class LimitAlerts
{
    private const ORDER = ['crit' => 0, 'config' => 1, 'warn' => 2, 'info' => 3];

    /**
     * @param list<EntityLimits> $entities
     *
     * @return list<array{kind: 'config'|'crit'|'info'|'warn', entityName: string, status: ?LimitStatus, refYear: int, usedYear: ?int, country: string}>
     */
    public static function fromEntities(array $entities): array
    {
        $rows = [];

        foreach ($entities as $entity) {
            if ($entity->noLimit) {
                $rows[] = self::row('info', $entity, null, null);
            } elseif ($entity->usedYear !== null) {
                $rows[] = self::row('info', $entity, null, $entity->usedYear);
            }

            foreach ($entity->statuses as $status) {
                if ($status->error !== null) {
                    $rows[] = self::row('config', $entity, $status, null);
                } elseif ($status->level === 'crit') {
                    $rows[] = self::row('crit', $entity, $status, null);
                } elseif ($status->level === 'warn') {
                    $rows[] = self::row('warn', $entity, $status, null);
                }
            }
        }

        // Stabilne zoradenie: rozhoduje druh, potom povodne poradie.
        $indexed = [];

        foreach ($rows as $i => $row) {
            $indexed[] = [self::ORDER[$row['kind']], $i, $row];
        }

        usort($indexed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $x): array => $x[2], $indexed);
    }

    /**
     * @param 'config'|'crit'|'info'|'warn' $kind
     *
     * @return array{kind: 'config'|'crit'|'info'|'warn', entityName: string, status: ?LimitStatus, refYear: int, usedYear: ?int, country: string}
     */
    private static function row(string $kind, EntityLimits $entity, ?LimitStatus $status, ?int $usedYear): array
    {
        return ['kind' => $kind, 'entityName' => $entity->entityName, 'status' => $status, 'refYear' => $entity->refYear, 'usedYear' => $usedYear, 'country' => $entity->country];
    }
}
