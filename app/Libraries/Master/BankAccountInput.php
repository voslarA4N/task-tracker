<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * Whitelist, normalizacia a validacia vstupu bankoveho uctu (ENT-06). Bez DB.
 */
final class BankAccountInput
{
    public const FIELDS = ['label', 'iban', 'swift', 'currency', 'is_default'];

    /**
     * @return array<string, string>
     */
    public static function fromPost(IncomingRequest $request, bool $editing): array
    {
        $out = [];

        foreach (self::FIELDS as $field) {
            if ($field === 'currency' && $editing) {
                continue;
            }

            if ($field === 'is_default') {
                $out[$field] = $request->getPost($field) === null ? '0' : '1';

                continue;
            }

            $value       = $request->getPost($field);
            $out[$field] = is_string($value) ? trim($value) : '';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $v
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $v): array
    {
        $swift = strtoupper((string) preg_replace('/\s+/u', '', (string) ($v['swift'] ?? '')));

        $out = [
            'label'      => trim((string) ($v['label'] ?? '')),
            'iban'       => Iban::normalize((string) ($v['iban'] ?? '')),
            'swift'      => $swift === '' ? null : $swift,
            'is_default' => in_array($v['is_default'] ?? false, [true, '1', 1, 'on'], true),
        ];

        if (array_key_exists('currency', $v)) {
            $out['currency'] = strtoupper(trim((string) $v['currency']));
        }

        return $out;
    }

    /**
     * @param array<string, mixed>          $v normalizovane hodnoty
     * @param (callable(string): bool)|null $currencyActive overi menu (predvolene tabulka currencies)
     *
     * @return array<string, string>
     */
    public static function validate(array $v, bool $editing, ?callable $currencyActive = null): array
    {
        $errors = [];
        $label  = (string) ($v['label'] ?? '');
        $length = mb_strlen($label);

        if ($length < 1 || $length > 60) {
            $errors['label'] = lang('Entities.errorBankLabel');
        }

        if (! Iban::isValid((string) ($v['iban'] ?? ''))) {
            $errors['iban'] = lang('Entities.errorIban');
        }

        $swift = $v['swift'] ?? null;

        if ($swift !== null && $swift !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', (string) $swift) !== 1) {
            $errors['swift'] = lang('Entities.errorSwift');
        }

        $currencyActive ??= static fn (string $c): bool => (new CurrencyRepository())->isActive($c);

        if (! $editing && ! $currencyActive((string) ($v['currency'] ?? ''))) {
            $errors['currency'] = lang('Entities.errorBankCurrency');
        }

        return $errors;
    }
}
