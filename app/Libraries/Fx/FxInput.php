<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use InvalidArgumentException;

/**
 * Whitelist a validacia rucneho kurzu z formulara (D-13). Iba retazce a bcmath, nikdy float.
 * Server neprijima zdroj, cislo tabulky ani sumu, rucny kurz ma vzdy source = 'manual'.
 */
final readonly class FxInput
{
    public const MAX_LEN = 32;

    /**
     * @param array<string, string> $errors fx_rate|fx_date => invalid|decimals|date_invalid|date_future|date_old
     */
    private function __construct(
        public string $mode,
        public ?string $rate,
        public ?string $date,
        public ?string $anchor,
        public bool $retry,
        public array $errors,
        public string $rawRate = '',
        public string $rawDate = '',
    ) {
    }

    public static function auto(): self
    {
        return new self('auto', null, null, null, false, []);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw, string $today): self
    {
        $get = static function (string $k) use ($raw): string {
            $v = $raw[$k] ?? '';

            return is_string($v) && mb_strlen($v) <= self::MAX_LEN ? trim($v) : '';
        };

        $retry  = $get('fx_retry') === '1';
        $anchor = self::validDate($get('fx_anchor'));

        if ($get('fx_mode') !== 'manual') {
            return new self('auto', null, null, $anchor, $retry, []);
        }

        $errors  = [];
        $rawRate = $get('fx_rate');
        $rate    = self::normalizeRate($rawRate, $errors);
        $rawDate = $get('fx_date');
        $date    = null;

        if ($rawDate !== '') {
            $date = self::validDate($rawDate);

            if ($date === null) {
                $errors['fx_date'] = 'date_invalid';
            } elseif ($date > $today) {
                $errors['fx_date'] = 'date_future';
                $date              = null;
            } else {
                $min = service('clock')->parseDate($today)?->modify('-1 year')->format('Y-m-d') ?? $today;

                if ($date < $min) {
                    $errors['fx_date'] = 'date_old';
                    $date              = null;
                }
            }
        }

        return new self('manual', $rate, $date, $anchor, $retry, $errors, $rawRate, $rawDate);
    }

    public function isManual(): bool
    {
        return $this->mode === 'manual' && $this->errors === [];
    }

    /**
     * @param array<string, string> $errors
     */
    private static function normalizeRate(string $in, array &$errors): ?string
    {
        $r = str_replace(',', '.', $in);

        if (preg_match('/^(\d+)(?:\.(\d+))?$/D', $r, $m) !== 1) {
            $errors['fx_rate'] = 'invalid';

            return null;
        }

        if (isset($m[2]) && strlen($m[2]) > 8) {
            $errors['fx_rate'] = 'decimals';

            return null;
        }

        $r = (string) preg_replace('/^0+(?=\d)/', '', $r);

        try {
            // jedno miesto pravdy pre rozsah a kladnost kurzu (regex + bcmath vo FxConverter)
            FxConverter::assertRate($r, 1, 'multiply');
        } catch (InvalidArgumentException) {
            $errors['fx_rate'] = 'invalid';

            return null;
        }

        return $r;
    }

    private static function validDate(string $s): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $s) !== 1) {
            return null;
        }

        return service('clock')->parseDate($s) === null ? null : $s;
    }
}
