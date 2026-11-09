<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use App\Libraries\Work\BillingMonth;
use InvalidArgumentException;

/**
 * Obdobie prehladu: mesiac, rok alebo rozsah mesiacov. from je prvy den (vratane), until prvy den
 * nasledujuceho obdobia (bez). Ciste celociselna aritmetika, bez date() a DateTimeImmutable.
 */
final readonly class ReportPeriod
{
    public const MAX_RANGE_MONTHS = 12;
    private const DAYS            = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    /**
     * @param 'month'|'range'|'year' $kind
     */
    private function __construct(
        public string $kind,
        public string $from,
        public string $until,
        public string $key,
        private string $firstMonth,
        private string $lastMonth,
    ) {
    }

    /**
     * Vstup musi byt uz parsovany cez BillingMonth::parse (pevna sirka RRRR-MM). Hranica zodpoveda year(): 2000..2100.
     */
    public static function isInBounds(string $ym): bool
    {
        return $ym >= '2000-01' && $ym <= '2100-12';
    }

    public static function month(string $ym): self
    {
        if (BillingMonth::parse($ym) === null) {
            throw new InvalidArgumentException('month');
        }

        if (! self::isInBounds($ym)) {
            throw new InvalidArgumentException('out_of_range');
        }

        return new self('month', $ym . '-01', BillingMonth::next($ym) . '-01', $ym, $ym, $ym);
    }

    public static function year(int $year): self
    {
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException('year');
        }

        $y = sprintf('%04d', $year);

        return new self('year', $y . '-01-01', sprintf('%04d-01-01', $year + 1), $y, $y . '-01', $y . '-12');
    }

    public static function range(string $fromYm, string $toYm): self
    {
        if (BillingMonth::parse($fromYm) === null || BillingMonth::parse($toYm) === null) {
            throw new InvalidArgumentException('month');
        }

        if (! self::isInBounds($fromYm) || ! self::isInBounds($toYm)) {
            throw new InvalidArgumentException('out_of_range');
        }

        if ($fromYm > $toYm) {
            throw new InvalidArgumentException('order');
        }

        if (self::index($toYm) - self::index($fromYm) + 1 > self::MAX_RANGE_MONTHS) {
            throw new InvalidArgumentException('too_long');
        }

        return new self('range', $fromYm . '-01', BillingMonth::next($toYm) . '-01', $fromYm . '_' . $toYm, $fromYm, $toYm);
    }

    /**
     * @return list<string>
     */
    public function months(): array
    {
        $out = [];

        for ($m = $this->firstMonth; $m <= $this->lastMonth; $m = BillingMonth::next($m)) {
            $out[] = $m;
        }

        return $out;
    }

    /**
     * Posledny den obdobia (den pred until).
     */
    public function lastDay(): string
    {
        $year  = (int) substr($this->lastMonth, 0, 4);
        $month = (int) substr($this->lastMonth, 5, 2);
        $days  = self::DAYS[$month - 1];
        $leap  = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);

        if ($month === 2 && $leap) {
            $days = 29;
        }

        return sprintf('%s-%02d', $this->lastMonth, $days);
    }

    public function previous(): self
    {
        return match ($this->kind) {
            'month' => self::month(BillingMonth::previous($this->firstMonth)),
            'year'  => self::year((int) $this->key - 1),
            default => throw new InvalidArgumentException('range'),
        };
    }

    public function next(): self
    {
        return match ($this->kind) {
            'month' => self::month(BillingMonth::next($this->firstMonth)),
            'year'  => self::year((int) $this->key + 1),
            default => throw new InvalidArgumentException('range'),
        };
    }

    public function isAfterMonth(string $currentYm): bool
    {
        return $this->firstMonth > $currentYm;
    }

    private static function index(string $ym): int
    {
        return (int) substr($ym, 0, 4) * 12 + (int) substr($ym, 5, 2) - 1;
    }
}
