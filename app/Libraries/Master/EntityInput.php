<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use CodeIgniter\HTTP\IncomingRequest;
use DateTimeImmutable;

/**
 * Whitelist a validácia vstupu subjektu (ENT-02, ENT-05, ENT-07). Bez DB.
 */
final class EntityInput
{
    public const BASE_FIELDS    = ['name_internal', 'country', 'currency', 'active_from', 'active_to'];
    public const VERSION_FIELDS = [
        'trade_name', 'address_line1', 'address_line2', 'postal_code', 'city', 'company_id', 'regon', 'tax_id',
        'vat_id', 'registry_note', 'email', 'phone', 'doc_language', 'vat_regime', 'due_days',
    ];

    private const MIN_DATE = '2000-01-01';

    private const OPTIONAL = ['address_line2', 'regon', 'tax_id', 'vat_id', 'registry_note', 'phone'];

    /**
     * @return array<string, string>
     */
    public static function baseFromPost(IncomingRequest $request): array
    {
        return self::pick($request, self::BASE_FIELDS);
    }

    /**
     * @return array<string, string>
     */
    public static function versionFromPost(IncomingRequest $request): array
    {
        return self::pick($request, self::VERSION_FIELDS);
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, string>
     */
    private static function pick(IncomingRequest $request, array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            $value       = $request->getPost($field);
            $out[$field] = is_string($value) ? trim($value) : '';
        }

        return $out;
    }

    /**
     * @param array<string, string|null> $v
     *
     * @return array<string, string|null>
     */
    public static function normalizeVersion(array $v): array
    {
        $out = [];

        foreach (self::VERSION_FIELDS as $field) {
            $value = trim((string) ($v[$field] ?? ''));

            if (in_array($field, ['vat_id', 'company_id', 'regon', 'tax_id'], true)) {
                $value = strtoupper((string) preg_replace('/\s+/u', '', $value));
            } elseif ($field === 'email') {
                $value = strtolower($value);
            }

            $out[$field] = $value === '' && in_array($field, self::OPTIONAL, true) ? null : $value;
        }

        return $out;
    }

    public static function parseDate(string $s): ?string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);

        return $d !== false && $d->format('Y-m-d') === $s ? $s : null;
    }

    /**
     * @param array<string, string|null> $v
     *
     * @return array<string, string>
     */
    public static function validateBase(array $v, string $today): array
    {
        $errors = [];
        $name   = trim((string) ($v['name_internal'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name_internal'] = lang('Entities.errorLength', [1, 100]);
        }

        if (Country::tryFrom((string) ($v['country'] ?? '')) === null) {
            $errors['country'] = lang('Entities.errorChoice');
        }

        if (Currency::tryFrom((string) ($v['currency'] ?? '')) === null) {
            $errors['currency'] = lang('Entities.errorChoice');
        }

        $from = self::parseDate((string) ($v['active_from'] ?? ''));
        $max  = (DateTimeImmutable::createFromFormat('!Y-m-d', $today))->modify('+366 days')->format('Y-m-d');

        if ($from === null || $from < self::MIN_DATE || $from > $max) {
            $errors['active_from'] = lang('Entities.errorDateRange', [self::fmt(self::MIN_DATE), self::fmt($max)]);
        }

        $toRaw = (string) ($v['active_to'] ?? '');

        if ($toRaw !== '') {
            $to = self::parseDate($toRaw);

            if ($to === null) {
                $errors['active_to'] = lang('Entities.errorDate');
            } elseif ($from !== null && $to < $from) {
                $errors['active_to'] = lang('Entities.errorActiveToBeforeFrom');
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string|null> $v
     *
     * @return array<string, string>
     */
    public static function validateVersion(array $v, Country $country): array
    {
        $errors = [];
        $get    = static fn (string $k): string => (string) ($v[$k] ?? '');
        $format = lang('Entities.errorFormat');

        $text = static function (string $key, int $min, int $max) use (&$errors, $get): void {
            $len = mb_strlen($get($key));

            if ($len < $min || $len > $max) {
                $errors[$key] = lang('Entities.errorLength', [$min, $max]);
            }
        };

        $text('trade_name', 1, 200);
        $text('address_line1', 1, 200);
        $text('address_line2', 0, 200);
        $text('city', 1, 100);
        $text('registry_note', 0, 500);

        if (preg_match('/^[0-9A-Z -]{3,10}$/D', $get('postal_code')) !== 1) {
            $errors['postal_code'] = $format;
        }

        $idPattern = $country === Country::PL ? '/^\d{10}$/D' : '/^\d{8}$/D';

        if (preg_match($idPattern, $get('company_id')) !== 1) {
            $errors['company_id'] = $format;
        }

        if ($get('regon') !== '') {
            if ($country !== Country::PL) {
                $errors['regon'] = lang('Entities.errorRegonOnlyPl');
            } elseif (preg_match('/^\d{9}(\d{5})?$/D', $get('regon')) !== 1) {
                $errors['regon'] = $format;
            }
        }

        if ($get('tax_id') !== '' && preg_match('/^[A-Z0-9]{4,20}$/D', $get('tax_id')) !== 1) {
            $errors['tax_id'] = $format;
        }

        if ($get('vat_id') !== '') {
            if (preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/D', $get('vat_id')) !== 1) {
                $errors['vat_id'] = $format;
            } elseif (! str_starts_with($get('vat_id'), $country->value)) {
                $errors['vat_id'] = lang('Entities.errorVatPrefix', [$country->value]);
            }
        }

        $email = $get('email');

        if ($email === '' || preg_match('/[\r\n]/', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            $errors['email'] = lang('Entities.errorEmail');
        }

        if ($get('phone') !== '' && preg_match('/^[+0-9 ()\/-]{6,30}$/D', $get('phone')) !== 1) {
            $errors['phone'] = $format;
        }

        if (DocLanguage::tryFrom($get('doc_language')) === null) {
            $errors['doc_language'] = lang('Entities.errorChoice');
        }

        $regime = VatRegime::tryFrom($get('vat_regime'));

        if ($regime === null) {
            $errors['vat_regime'] = lang('Entities.errorChoice');
        } elseif ($regime->country() !== $country) {
            $errors['vat_regime'] = lang('Entities.errorRegimeCountry');
        }

        if (preg_match('/^\d{1,3}$/D', $get('due_days')) !== 1 || (int) $get('due_days') > 365) {
            $errors['due_days'] = lang('Entities.errorDueDays');
        }

        return $errors;
    }

    private static function fmt(string $iso): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        return $d === false ? $iso : $d->format('j. n. Y');
    }
}
