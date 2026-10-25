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
    public const MAX_OUTPUT  = 30;
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

        $tokens = [];
        $years  = 0;
        $months = 0;
        $seqs   = 0;
        $digits = 0;
        $length = strlen($format);

        for ($i = 0; $i < $length;) {
            $rest = substr($format, $i);

            if (str_starts_with($rest, 'RRRR')) {
                $tokens[] = ['year4', 'RRRR'];
                $years++;
                $i += 4;
            } elseif (str_starts_with($rest, 'RR')) {
                $tokens[] = ['year2', 'RR'];
                $years++;
                $i += 2;
            } elseif (str_starts_with($rest, 'MM')) {
                $tokens[] = ['month', 'MM'];
                $months++;
                $i += 2;
            } elseif ($rest[0] === '#') {
                $run      = strspn($rest, '#');
                $tokens[] = ['seq', str_repeat('#', $run)];
                $seqs++;
                $digits = $run;
                $i += $run;
            } else {
                if (preg_match('/^[A-Za-z0-9\/_.-]$/D', $rest[0]) !== 1) {
                    throw new InvalidSeriesFormat('bad_char');
                }

                $last = array_key_last($tokens);

                if ($last !== null && $tokens[$last][0] === 'lit') {
                    $tokens[$last][1] .= $rest[0];
                } else {
                    $tokens[] = ['lit', $rest[0]];
                }

                $i++;
            }
        }

        self::assertUnambiguous($tokens);

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
     * Pevný text sa neescapuje, preto písmeno tesne pri tokene RR, RRRR alebo MM znamená, že parser mohol
     * zobrať časť zamýšľaného textu ako dátum (COMM-RRRR### = CO + mesiac, ERR-... = E + rok). Formát sa po
     * vystavení prvého dokladu zamkne (D-10), preto sa taký zápis odmietne a text sa musí oddeliť
     * znakom - / _ . alebo číslicou (WR-06).
     *
     * @param list<array{string, string}> $tokens
     */
    private static function assertUnambiguous(array $tokens): void
    {
        $last = count($tokens) - 1;

        foreach ($tokens as $i => [$type, $value]) {
            if (! in_array($type, ['year4', 'year2', 'month'], true)) {
                continue;
            }

            $before = $i > 0 && $tokens[$i - 1][0] === 'lit' ? substr($tokens[$i - 1][1], -1) : '';
            $after  = $i < $last && $tokens[$i + 1][0] === 'lit' ? $tokens[$i + 1][1][0] : '';

            if (ctype_alpha($before) || ctype_alpha($after)) {
                throw new InvalidSeriesFormat('ambiguous_literal');
            }
        }
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
        return (int) str_repeat('9', $this->digits);
    }

    public function format(): string
    {
        return $this->format;
    }

    /**
     * Môžu dva formáty pre nejaký dátum a poradie vytvoriť rovnaké číslo (WR-05)?
     *
     * Všetky tokeny majú pevnú šírku, preto každý výstup má pevnú dĺžku a na každej pozícii buď
     * pevný znak, alebo číslicu. Odhad je konzervatívny: číslicové pozície (rok, mesiac, poradie)
     * sa berú ako ľubovoľná číslica, takže výsledok "nie" znamená istotu, že sa čísla nikdy nezhodnú.
     * Písmená sa porovnávajú bez ohľadu na veľkosť (variabilný symbol a ľudia ich nerozlišujú).
     */
    public function mayCollideWith(self $other): bool
    {
        $mine   = $this->shape();
        $theirs = $other->shape();

        if (count($mine) !== count($theirs)) {
            return false;
        }

        foreach ($mine as $i => $char) {
            $that = $theirs[$i];

            if ($char === null && $that === null) {
                continue;
            }

            if ($char === null || $that === null) {
                // Pevný znak oproti číslici: zhoda len vtedy, ak je pevný znak sám číslicou.
                if (ctype_digit($char ?? $that)) {
                    continue;
                }

                return false;
            }

            if ($char !== $that) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tvar výstupu po znakoch: null = číslica z roku, mesiaca alebo poradia, inak pevný znak (veľké písmeno).
     *
     * @return list<string|null>
     */
    private function shape(): array
    {
        $shape = [];

        foreach ($this->tokens as [$type, $value]) {
            if ($type === 'lit') {
                foreach (str_split(strtoupper($value)) as $char) {
                    $shape[] = $char;
                }

                continue;
            }

            for ($n = strlen($value); $n > 0; $n--) {
                $shape[] = null;
            }
        }

        return $shape;
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
