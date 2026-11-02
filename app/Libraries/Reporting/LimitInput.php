<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use App\Libraries\Platform\Money;
use CodeIgniter\HTTP\IncomingRequest;
use InvalidArgumentException;

/**
 * Whitelist, normalizacia a validacia vstupu limitu obratu (LIM-01). Bez DB, bez lang().
 */
final class LimitInput
{
    public const FIELDS    = ['country', 'year', 'name', 'amount', 'currency', 'calc_method', 'thresholds'];
    public const COUNTRIES = ['SK', 'PL', 'CZ'];

    /**
     * @return array<string, string>
     */
    public static function fromPost(IncomingRequest $request): array
    {
        $out = [];

        foreach ([...self::FIELDS, 'revision'] as $field) {
            $value       = $request->getPost($field);
            $out[$field] = is_string($value) ? trim($value) : '';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{country: string, year: string, name: string, amount: string, currency: string, calc_method: string, thresholds: string, revision: string}
     */
    public static function normalize(array $raw): array
    {
        $str = static fn (string $k): string => is_scalar($raw[$k] ?? null) ? trim((string) $raw[$k]) : '';

        return [
            'country'     => strtoupper($str('country')),
            'year'        => $str('year'),
            'name'        => $str('name'),
            'amount'      => str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}]+/u', '', $str('amount'))),
            'currency'    => strtoupper($str('currency')),
            'calc_method' => $str('calc_method'),
            'thresholds'  => str_replace(';', ',', (string) preg_replace('/[\s\x{00A0}]+/u', '', $str('thresholds'))),
            'revision'    => $str('revision'),
        ];
    }

    /**
     * @param array<string, string>      $v              normalizovane hodnoty
     * @param callable(string): bool     $currencyActive overi menu
     *
     * @return array<string, string> pole => lang kluc
     */
    public static function validate(array $v, callable $currencyActive): array
    {
        $errors = [];

        if (! in_array($v['country'] ?? '', self::COUNTRIES, true)) {
            $errors['country'] = 'Limits.errCountry';
        }

        $year = $v['year'] ?? '';

        if (preg_match('/^\d{4}$/D', $year) !== 1 || (int) $year < 2000 || (int) $year > 2100) {
            $errors['year'] = 'Limits.errYear';
        }

        $length = mb_strlen($v['name'] ?? '');

        if ($length < 1 || $length > 120) {
            $errors['name'] = 'Limits.errName';
        }

        if (! self::amountValid($v['amount'] ?? '')) {
            $errors['amount'] = 'Limits.errAmount';
        }

        if (! $currencyActive($v['currency'] ?? '')) {
            $errors['currency'] = 'Limits.errCurrency';
        }

        if (! in_array($v['calc_method'] ?? '', PeriodResolver::METHODS, true)) {
            $errors['calc_method'] = 'Limits.errMethod';
        }

        if (! self::thresholdsValid($v['thresholds'] ?? '')) {
            $errors['thresholds'] = 'Limits.errThresholds';
        }

        return $errors;
    }

    /**
     * @return list<int> unikatne, vzostupne
     */
    public static function thresholds(string $csv): array
    {
        if ($csv === '') {
            return [];
        }

        $values = array_values(array_unique(array_map('intval', explode(',', $csv))));
        sort($values);

        return $values;
    }

    /**
     * @param array<string, string> $v iba po uspesnej validacii
     *
     * @return array{country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds_csv: string}
     */
    public static function toRow(array $v): array
    {
        return [
            'country'        => $v['country'],
            'year'           => (int) $v['year'],
            'name'           => $v['name'],
            'amount'         => Money::of($v['amount'])->toDecimalString(),
            'currency'       => $v['currency'],
            'calc_method'    => $v['calc_method'],
            'thresholds_csv' => implode(',', self::thresholds($v['thresholds'])),
        ];
    }

    private static function amountValid(string $amount): bool
    {
        try {
            $money = Money::of($amount);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $money->compare(Money::zero()) > 0;
    }

    private static function thresholdsValid(string $csv): bool
    {
        if (preg_match('/^\d{1,3}(,\d{1,3})*$/D', $csv) !== 1) {
            return false;
        }

        $values = self::thresholds($csv);

        if ($values === [] || count($values) > 3) {
            return false;
        }

        foreach ($values as $t) {
            if ($t < 1 || $t > 200) {
                return false;
            }
        }

        return true;
    }
}
