<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed;

use InvalidArgumentException;

/**
 * Rozsah a "sprava v case" vymysleneho sveta (D-03). Cista konfiguracia bez DB a bez nahody.
 *
 * Klient (identita je v Catalog::CLIENTS):
 *  - cadence: monthly (praca kazdy mesiac), every_other (kazdy druhy mesiac), quarterly_light (raz za stvrtrok)
 *  - tasks: [min, max] uloh v aktivnom mesiaci
 *  - split_pct: sanca (%) rozdelenej ulohy na 2 az 3 casti v po sebe iducich mesiacoch (len monthly)
 *  - partial_months: mesiace s dodatocnou faktúrou za casti k 15. dnu
 *  - skip_last_full_month: posledny cely mesiac sa nevyfakturuje (scenar neskorej fakturacie)
 *  - offset: pocet pracovnych dni po prvom pracovnom dni nasledujuceho mesiaca, kedy sa vystavuje
 */
final readonly class Profile
{
    public const SEED = 20260101;

    /**
     * @param list<string>               $entities klucie z Catalog::ENTITIES
     * @param list<array<string, mixed>> $clients
     */
    public function __construct(
        public string $name,
        public array $entities,
        public array $clients,
    ) {
    }

    public static function full(): self
    {
        return new self('full', ['sk', 'cz', 'pl'], [
            self::client('sk_orbit', 'sk', '2026-01', 'monthly', [3, 5], 25, ['2026-03'], false, 0, '36.00', null, '45.00'),
            self::client('sk_alfa', 'sk', '2026-02', 'every_other', [3, 5], 0, [], false, 1, '30.00', 30, null),
            self::client('sk_beta', 'sk', '2026-02', 'every_other', [2, 4], 0, ['2026-08'], false, 2, '27.00', null, null),
            self::client('cz_vltava', 'cz', '2026-03', 'monthly', [3, 5], 30, ['2026-07'], true, 1, '820.00', 21, '1000.00'),
            self::client('cz_hana', 'cz', '2026-03', 'every_other', [2, 4], 0, [], false, 3, '750.00', null, null),
            self::client('pl_wisla', 'pl', '2026-05', 'monthly', [2, 4], 20, [], false, 0, '140.00', null, '160.00'),
            self::client('pl_rhein', 'pl', '2026-02', 'every_other', [2, 4], 0, [], false, 2, '150.00', 30, null),
            self::client('pl_alpen', 'pl', '2026-01', 'quarterly_light', [2, 3], 0, [], false, 1, '135.00', null, null),
        ]);
    }

    public static function small(): self
    {
        return new self('small', ['sk', 'cz', 'pl'], [
            self::client('sk_orbit', 'sk', '2026-01', 'monthly', [2, 3], 0, ['2026-02'], false, 0, '36.00', null, null),
            self::client('cz_vltava', 'cz', '2026-01', 'monthly', [2, 3], 0, [], false, 1, '820.00', null, null),
            self::client('pl_wisla', 'pl', '2026-01', 'monthly', [2, 3], 0, [], false, 0, '140.00', null, null),
        ]);
    }

    public static function byName(string $name): self
    {
        return match ($name) {
            'full'  => self::full(),
            'small' => self::small(),
            default => throw new InvalidArgumentException('Unknown profile, use full or small.'),
        };
    }

    /**
     * @param array{int, int} $tasks
     * @param list<string>    $partialMonths
     *
     * @return array<string, mixed>
     */
    private static function client(
        string $key,
        string $entity,
        string $firstMonth,
        string $cadence,
        array $tasks,
        int $splitPct,
        array $partialMonths,
        bool $skipLastFullMonth,
        int $offset,
        string $rate,
        ?int $dueDays,
        ?string $overrideRate,
    ): array {
        return [
            'key'                  => $key,
            'entity'               => $entity,
            'first_month'          => $firstMonth,
            'cadence'              => $cadence,
            'tasks'                => $tasks,
            'split_pct'            => $splitPct,
            'partial_months'       => $partialMonths,
            'skip_last_full_month' => $skipLastFullMonth,
            'offset'               => $offset,
            'hourly_rate'          => $rate,
            'due_days'             => $dueDays,
            'override_rate'        => $overrideRate,
        ];
    }
}
