<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed;

use App\Libraries\Platform\Clock;
use DateTimeImmutable;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Ciste deterministicke planovanie vymysleneho sveta od 2026-01-01 do "dnes" (D-03). Ziadna DB, ziadne hodiny
 * (dnes prichadza z parametra) a ziadna nahoda mimo Randomizer(Mt19937($seed)): rovnaky vstup da rovnaky plan.
 *
 * Pravidla: pracovny den = po az pi (sviatky sa neriesia), aktualny mesiac sa nefakturuje, nic nie je po "dnes".
 */
final class HistoryPlanner
{
    public const START = '2026-01-01';

    /**
     * Odhady uloh v stvrthodinach (16 = 4 h).
     */
    private const ESTIMATES = [4, 6, 8, 8, 12, 16, 16, 24, 32, 40];

    private readonly Randomizer $rng;
    private readonly Clock $clock;
    private readonly int $startIdx;
    private readonly int $currentIdx;
    private int $seq = 0;

    private function __construct(private readonly Profile $profile, private readonly string $today, private readonly int $seed)
    {
        $this->rng        = new Randomizer(new Mt19937($seed));
        $this->clock      = new Clock('Europe/Bratislava');
        $this->startIdx   = $this->monthIdx(substr(self::START, 0, 7));
        $this->currentIdx = $this->monthIdx(substr($today, 0, 7));
    }

    public static function build(Profile $profile, string $today, int $seed = Profile::SEED): HistoryPlan
    {
        if ((new Clock('Europe/Bratislava'))->parseDate($today) === null || $today < self::START) {
            throw new InvalidArgumentException('Today must be a valid date on or after ' . self::START . '.');
        }

        return (new self($profile, $today, $seed))->plan();
    }

    private function plan(): HistoryPlan
    {
        $entities = $this->entities();
        $clients  = $this->clients();
        $tasks    = $this->tasks($clients);

        return new HistoryPlan($this->today, $this->seed, $entities, $clients, $tasks, $this->invoices($clients, $tasks, $entities));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entities(): array
    {
        $out = [];

        foreach ($this->profile->entities as $key) {
            $e     = Catalog::ENTITIES[$key];
            $later = [];

            foreach ($e['later_versions'] as $v) {
                if ($v['valid_from'] <= $this->today) {
                    $later[] = $v;
                }
            }

            $out[] = [
                'key'            => $key,
                'country'        => $e['country'],
                'currency'       => $e['currency'],
                'name_internal'  => $e['name_internal'],
                'active_from'    => self::START,
                'series_format'  => $e['series_format'],
                'vat_regime'     => $e['version']['vat_regime'],
                'doc_language'   => $e['version']['doc_language'],
                'due_days'       => (int) $e['version']['due_days'],
                'version'        => $e['version'],
                'bank_accounts'  => $e['bank_accounts'],
                'later_versions' => $later,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function clients(): array
    {
        $currencies = [];

        foreach ($this->profile->entities as $key) {
            $currencies[$key] = Catalog::ENTITIES[$key]['currency'];
        }

        $out = [];

        foreach ($this->profile->clients as $spec) {
            if ($this->monthIdx((string) $spec['first_month']) > $this->currentIdx) {
                continue;
            }

            $identity = Catalog::CLIENTS[$spec['key']];
            $out[]    = $spec + $identity + ['currency' => $currencies[$spec['entity']]];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $clients
     *
     * @return list<array<string, mixed>>
     */
    private function tasks(array $clients): array
    {
        $raw     = [];
        $titles  = [];
        $tickets = [];

        foreach ($clients as $c) {
            $first = max($this->monthIdx((string) $c['first_month']), $this->startIdx);

            for ($idx = $first; $idx <= $this->currentIdx; $idx++) {
                if (! $this->active($c, $idx)) {
                    continue;
                }

                $days = $this->workdaysIn($this->monthStart($idx), $this->minDate($this->monthEnd($idx), $this->today));
                $n    = $this->rng->getInt((int) $c['tasks'][0], (int) $c['tasks'][1]);

                if ($days === []) {
                    continue;
                }

                // Mesiac s dodatocnou fakturou: prva uloha sa zacne a zapracuje pred 15. dnom, aby mala faktura co obsahovat.
                $early = in_array($this->ym($idx), $c['partial_months'], true) ? $this->ym($idx) . '-13' : null;

                for ($i = 0; $i < $n; $i++) {
                    $pool = $i === 0 && $early !== null ? array_values(array_filter($days, static fn (string $d): bool => $d <= $early)) : $days;
                    $pool = $pool === [] ? $days : $pool;
                    $day  = $raw === [] ? $days[0] : $pool[$this->rng->getInt(0, count($pool) - 1)];
                    $hour = $this->rng->getInt(8, 15);
                    $at   = $day . 'T' . sprintf('%02d:%02d:00', $hour, $this->rng->getInt(0, 3) * 15);

                    $raw[] = $this->task($c, $idx, $day, $hour, $at, $i === 0 ? $early : null, $titles, $tickets);
                }
            }
        }

        usort($raw, static fn (array $a, array $b): int => [$a['created_at'], $a['seq']] <=> [$b['created_at'], $b['seq']]);

        $out = [];

        foreach ($raw as $i => $t) {
            unset($t['seq']);
            $out[] = ['key' => sprintf('T%04d', $i + 1)] + $t;
        }

        return $out;
    }

    /**
     * @param array<string, mixed>    $c
     * @param array<string, bool>     $titles
     * @param array<int|string, bool> $tickets
     *
     * @return array<string, mixed>
     */
    private function task(array $c, int $idx, string $day, int $hour, string $createdAt, ?string $cap, array &$titles, array &$tickets): array
    {
        $lang = Catalog::TITLES[(string) $c['lang']];

        for ($try = 0; $try < 6; $try++) {
            $title = $lang['actions'][$this->rng->getInt(0, count($lang['actions']) - 1)] . ' ' . $lang['objects'][$this->rng->getInt(0, count($lang['objects']) - 1)];

            if (! isset($titles[$c['key'] . $title])) {
                break;
            }
        }

        $titles[$c['key'] . $title] = true;

        $ticket = null;

        if ($c['portal_name'] !== null) {
            do {
                $ticket = (string) $this->rng->getInt(10000, 99999);
            } while (isset($tickets[$ticket]));

            $tickets[$ticket] = true;
        }

        $estimate = self::ESTIMATES[$this->rng->getInt(0, count(self::ESTIMATES) - 1)];
        $rate     = $c['override_rate'] !== null && $this->rng->getInt(1, 100) <= 5 ? (string) $c['override_rate'] : null;
        $isLast   = $idx === $this->currentIdx;

        $nParts = 1;

        if ($c['cadence'] === 'monthly' && ! $isLast && $this->rng->getInt(1, 100) <= (int) $c['split_pct']) {
            $nParts = $this->rng->getInt(1, 100) <= 30 ? 3 : 2;
            $nParts = min($nParts, $this->currentIdx - $idx + 1);
        }

        $parts     = [];
        $noPartYet = $isLast && $this->rng->getInt(1, 100) <= 25;

        if (! $noPartYet) {
            for ($k = 0; $k < $nParts; $k++) {
                $partIdx = $idx + $k;
                $from    = $k === 0 ? $day : $this->monthStart($partIdx);
                $until   = $this->minDate($this->monthEnd($partIdx), $this->today);
                $days    = $this->workdaysIn($from, $k === 0 && $cap !== null ? $this->minDate($until, $cap) : $until);

                if ($days === []) {
                    break;
                }

                $partDay = $days[$this->rng->getInt(0, count($days) - 1)];
                $minHour = $k === 0 && $partDay === $day ? $hour + 1 : 9;
                $time    = sprintf('%02d:%02d:00', $this->rng->getInt($minHour, 17), $this->rng->getInt(0, 3) * 15);

                $quarters = $nParts === 1
                    ? max(2, intdiv($estimate * $this->rng->getInt(80, 120), 100))
                    : max(2, intdiv($estimate, $nParts) + $this->rng->getInt(-2, 2));

                $parts[] = ['month' => $this->ym($partIdx), 'hours' => $this->quarters($quarters), 'at' => $partDay . 'T' . $time];
            }
        }

        $complete = $parts !== [] && count($parts) === $nParts;
        $done     = $complete && $this->rng->getInt(1, 100) <= ($isLast ? 60 : 96);

        return [
            'seq'        => $this->seq++,
            'client'     => $c['key'],
            'created_at' => $createdAt,
            'title'      => $title,
            'ticket'     => $ticket,
            'hours'      => $this->quarters($estimate),
            'rate'       => $rate,
            'parts'      => $parts,
            'done_at'    => $done ? $parts[count($parts) - 1]['at'] : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $clients
     * @param list<array<string, mixed>> $tasks
     * @param list<array<string, mixed>> $entities
     *
     * @return list<array<string, mixed>>
     */
    private function invoices(array $clients, array $tasks, array $entities): array
    {
        $dueDays = [];

        foreach ($entities as $e) {
            $dueDays[$e['key']] = $e['due_days'];
        }

        $events = [];

        foreach ($clients as $ci => $c) {
            $first     = max($this->monthIdx((string) $c['first_month']), $this->startIdx);
            $skipMonth = $c['skip_last_full_month'] ? $this->ym($this->currentIdx - 1) : null;
            $due       = (int) ($c['due_days'] ?? $dueDays[$c['entity']]);

            for ($idx = $first; $idx < $this->currentIdx; $idx++) {
                $month = $this->ym($idx);
                $parts = [];

                foreach ($tasks as $t) {
                    if ($t['client'] !== $c['key']) {
                        continue;
                    }

                    foreach ($t['parts'] as $pi => $p) {
                        if ($p['month'] === $month) {
                            $parts[] = ['task' => $t['key'], 'index' => $pi, 'at' => $p['at']];
                        }
                    }
                }

                if ($parts === [] || $month === $skipMonth) {
                    continue;
                }

                if (in_array($month, $c['partial_months'], true)) {
                    $date = $this->nextWorkday($month . '-15');

                    if ($date <= $this->today) {
                        $at  = $date . 'T' . sprintf('%02d:%02d:00', $this->rng->getInt(9, 10), $this->rng->getInt(0, 3) * 15);
                        $sub = array_values(array_filter($parts, static fn (array $p): bool => $p['at'] <= $at));

                        if ($sub !== []) {
                            $events[] = $this->event($ci, $c, $month, $at, $date, $due, 'partial', $sub);
                            $parts    = array_values(array_filter($parts, static fn (array $p): bool => $p['at'] > $at));
                        }
                    }
                }

                if ($parts === []) {
                    continue;
                }

                $issue = $this->addWorkdays($this->nextWorkday($this->monthStart($idx + 1)), (int) $c['offset']);

                if ($issue > $this->today) {
                    continue;
                }

                $at       = $issue . 'T' . sprintf('%02d:%02d:00', $this->rng->getInt(9, 10), $this->rng->getInt(0, 3) * 15);
                $events[] = $this->event($ci, $c, $month, $at, $this->monthEnd($idx), $due, 'monthly', $parts);
            }
        }

        usort($events, static fn (array $a, array $b): int => [$a['at'], $a['ci']] <=> [$b['at'], $b['ci']]);

        $out = [];

        foreach ($events as $i => $e) {
            unset($e['ci']);
            $out[] = ['key' => sprintf('I%03d', $i + 1), 'request_key' => $this->uuid()] + $e;
        }

        return $out;
    }

    /**
     * @param array<string, mixed>       $c
     * @param list<array<string, mixed>> $parts
     *
     * @return array<string, mixed>
     */
    private function event(int $ci, array $c, string $month, string $at, string $delivery, int $dueDays, string $kind, array $parts): array
    {
        $issue = substr($at, 0, 10);

        return [
            'ci'             => $ci,
            'at'             => $at,
            'client'         => $c['key'],
            'entity'         => $c['entity'],
            'month'          => $month,
            'issue_date'     => $issue,
            'delivery_date'  => $kind === 'partial' ? $issue : $delivery,
            'due_date'       => $this->addDays($issue, $dueDays),
            'kind'           => $kind,
            'payment_method' => 'transfer',
            'parts'          => array_map(static fn (array $p): array => ['task' => $p['task'], 'index' => $p['index']], $parts),
        ];
    }

    /**
     * @param array<string, mixed> $c
     */
    private function active(array $c, int $idx): bool
    {
        $d = $idx - $this->monthIdx((string) $c['first_month']);

        if ($d < 0) {
            return false;
        }

        return match ($c['cadence']) {
            'every_other'     => $d % 2 === 0,
            'quarterly_light' => $d % 3 === 0,
            default           => true,
        };
    }

    private function quarters(int $q): string
    {
        return (string) intdiv($q, 4) . '.' . ['00', '25', '50', '75'][$q % 4];
    }

    private function uuid(): string
    {
        $b    = $this->rng->getBytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        $h    = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    private function monthIdx(string $ym): int
    {
        [$y, $m] = explode('-', $ym);

        return (int) $y * 12 + (int) $m - 1;
    }

    private function ym(int $idx): string
    {
        return sprintf('%04d-%02d', intdiv($idx, 12), $idx % 12 + 1);
    }

    private function monthStart(int $idx): string
    {
        return $this->ym($idx) . '-01';
    }

    private function monthEnd(int $idx): string
    {
        return $this->date($this->monthStart($idx))->modify('last day of this month')->format('Y-m-d');
    }

    private function minDate(string $a, string $b): string
    {
        return $a < $b ? $a : $b;
    }

    private function date(string $date): DateTimeImmutable
    {
        return $this->clock->parseDate($date) ?? throw new InvalidArgumentException('Invalid date ' . $date);
    }

    private function addDays(string $date, int $n): string
    {
        return $this->date($date)->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    private function isWorkday(string $date): bool
    {
        return (int) $this->date($date)->format('N') < 6;
    }

    private function nextWorkday(string $date): string
    {
        while (! $this->isWorkday($date)) {
            $date = $this->addDays($date, 1);
        }

        return $date;
    }

    private function addWorkdays(string $date, int $n): string
    {
        while ($n > 0) {
            $date = $this->nextWorkday($this->addDays($date, 1));
            $n--;
        }

        return $date;
    }

    /**
     * @return list<string>
     */
    private function workdaysIn(string $from, string $to): array
    {
        $out = [];

        for ($d = $from; $d <= $to; $d = $this->addDays($d, 1)) {
            if ($this->isWorkday($d)) {
                $out[] = $d;
            }
        }

        return $out;
    }
}
