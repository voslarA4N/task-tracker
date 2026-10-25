<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use InvalidArgumentException;
use OverflowException;

/**
 * Format cisla dokladu (D-09): tokeny RRRR, RR, MM, blok # (pocet # = pocet cislic) a pevny text.
 * Cista logika bez DB a bez hodin, datum prichadza len z parametra. Pridelenie cisla je Phase 5 (D-11).
 */
final class SeriesFormat
{
    public const MAX_OUTPUT = 30;

    private const MAX_DIGITS = 9;

    /**
     * @param list<array{string, string}> $tokens dvojice [typ, hodnota]; typ: year4, year2, month, seq, lit
     */
    private function __construct(
        private readonly string $format,
        private readonly array $tokens,
        private readonly int $digits,
        private readonly bool $monthly,
    ) {
    }

    public static function parse(string $format): self
    {
        if ($format === '') {
            throw new InvalidSeriesFormat('empty');
        }

        $tokens  = [];
        $years   = 0;
        $months  = 0;
        $seqs    = 0;
        $digits  = 0;
        $length  = strlen($format);
        $literal = '';

        $flush = static function () use (&$tokens, &$literal): void {
            if ($literal !== '') {
                $tokens[] = ['lit', $literal];
                $literal  = '';
            }
        };

        for ($i = 0; $i < $length;) {
            $rest = substr($format, $i);

            if (str_starts_with($rest, 'RRRR')) {
                $flush();
                $tokens[] = ['year4', 'RRRR'];
                $years++;
                $i += 4;
            } elseif (str_starts_with($rest, 'RR')) {
                $flush();
                $tokens[] = ['year2', 'RR'];
                $years++;
                $i += 2;
            } elseif (str_starts_with($rest, 'MM')) {
                $flush();
                $tokens[] = ['month', 'MM'];
                $months++;
                $i += 2;
            } elseif ($rest[0] === '#') {
                $flush();
                $run = strspn($rest, '#');
                $tokens[] = ['seq', str_repeat('#', $run)];
                $seqs++;
                $digits = $run;
                $i += $run;
            } else {
                if (preg_match('/^[A-Za-z0-9\/_.-]$/D', $rest[0]) !== 1) {
                    throw new InvalidSeriesFormat('bad_char');
                }

                $literal .= $rest[0];
                $i++;
            }
        }

        $flush();

        if ($years === 0) {
            throw new InvalidSeriesFormat('no_year');
        }

        if ($years > 1) {
            throw new InvalidSeriesFormat('two_years');
        }

        if ($seqs === 0) {
            throw new InvalidSeriesFormat('no_sequence');
        }

        if ($seqs > 1) {
            throw new InvalidSeriesFormat('two_sequences');
        }

        if ($digits > self::MAX_DIGITS) {
            throw new InvalidSeriesFormat('sequence_too_long');
        }

        if ($months > 1) {
            throw new InvalidSeriesFormat('two_months');
        }

        $out = 0;

        foreach ($tokens as [$type, $value]) {
            $out += strlen($value);
        }

        if ($out > self::MAX_OUTPUT) {
            throw new InvalidSeriesFormat('too_long');
        }

        return new self($format, $tokens, $digits, $months === 1);
    }

    /**
     * @param string $date Y-m-d
     */
    public function render(string $date, int $sequence): string
    {
        if ($sequence < 1 || $sequence > $this->maxSequence()) {
            throw new OverflowException('Sequence out of range for format.');
        }

        [$year, $month] = $this->dateParts($date);
        $out            = '';

        foreach ($this->tokens as [$type, $value]) {
            $out .= match ($type) {
                'year4' => $year,
                'year2' => substr($year, 2),
                'month' => $month,
                'seq'   => str_pad((string) $sequence, $this->digits, '0', STR_PAD_LEFT),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * Kluc obdobia pre nulovanie poradia: RRRRMM pri mesacnom, RRRR pri rocnom.
     *
     * @param string $date Y-m-d
     */
    public function periodKey(string $date): string
    {
        [$year, $month] = $this->dateParts($date);

        return $this->monthly ? $year . $month : $year;
    }

    public function resetsMonthly(): bool
    {
        return $this->monthly;
    }

    public function digits(): int
    {
        return $this->digits;
    }

    public function maxSequence(): int
    {
        return 10 ** $this->digits - 1;
    }

    public function format(): string
    {
        return $this->format;
    }

    /**
     * @return array{string, string}
     */
    private function dateParts(string $date): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/D', $date, $m) !== 1) {
            throw new InvalidArgumentException('Invalid date.');
        }

        return [$m[1], $m[2]];
    }
}
