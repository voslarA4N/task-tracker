<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use App\Libraries\Platform\Money;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Whitelist, normalizacia a validacia vstupu klienta (CLI-01..03, CLI-05..07). Bez DB.
 */
final class ClientInput
{
    public const FIELDS = [
        'name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'identifier_type',
        'identifier_value', 'tax_id', 'vat_id', 'invoice_email', 'cc_emails', 'hourly_rate', 'currency',
        'doc_language', 'due_days', 'portal_name', 'portal_url_template', 'billing_mode', 'counts_toward_limit',
    ];

    /**
     * 27 krajin EU + vybrane dalsie.
     */
    public const COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU',
        'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'GB', 'CH', 'NO', 'UA', 'US',
    ];

    private const OPTIONAL = ['address_line2', 'identifier_value', 'tax_id', 'vat_id', 'doc_language', 'due_days', 'portal_name', 'portal_url_template'];

    /**
     * @return array<string, string>
     */
    public static function fromPost(IncomingRequest $request): array
    {
        $out = [];

        foreach (self::FIELDS as $field) {
            if ($field === 'counts_toward_limit') {
                $out[$field] = $request->getPost($field) === null ? '0' : '1';

                continue;
            }

            $value       = $request->getPost($field);
            $out[$field] = is_string($value) ? trim($value) : '';
        }

        return $out;
    }

    /**
     * @param array<string, string|null> $v
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $v): array
    {
        $get = static fn (string $k): string => trim((string) ($v[$k] ?? ''));
        $out = [];

        foreach (self::FIELDS as $field) {
            $value = $get($field);

            switch ($field) {
                case 'identifier_value':
                    // Iný identifikátor (zahraničný register) sa ukladá tak, ako ho validácia skontrolovala:
                    // s medzerami a veľkosťou písmen, len viacnásobné medzery sa zlúčia (WR-10).
                    $value = $get('identifier_type') === IdentifierType::Other->value
                        ? (string) preg_replace('/\s+/u', ' ', $value)
                        : strtoupper((string) preg_replace('/\s+/u', '', $value));
                    break;

                case 'vat_id':
                case 'tax_id':
                    $value = strtoupper((string) preg_replace('/\s+/u', '', $value));
                    break;

                case 'invoice_email':
                    $value = mb_strtolower($value);
                    break;

                case 'cc_emails':
                    $out[$field] = CcEmails::parse($value);

                    continue 2;

                case 'hourly_rate':
                    $out[$field] = Money::of(str_replace(',', '.', (string) preg_replace('/\s+/u', '', $value)))->__toString();

                    continue 2;

                case 'counts_toward_limit':
                    $out[$field] = in_array($value, ['1', 'true', 'on'], true);

                    continue 2;

                case 'due_days':
                    $out[$field] = $value === '' ? null : (int) $value;

                    continue 2;
            }

            $out[$field] = $value === '' && in_array($field, self::OPTIONAL, true) ? null : $value;
        }

        if (($out['identifier_type'] ?? '') === IdentifierType::None->value) {
            $out['identifier_value'] = null;
        }

        return $out;
    }

    /**
     * Validuje surove (fromPost) hodnoty; identifikatory porovnava po rovnakej normalizacii ako normalize().
     *
     * @param array<string, string|null>    $v
     * @param (callable(string): bool)|null $currencyActive overí menu (predvolene tabuľka currencies)
     *
     * @return array<string, string>
     */
    public static function validate(array $v, ?callable $currencyActive = null): array
    {
        $errors = [];
        $get    = static fn (string $k): string => trim((string) ($v[$k] ?? ''));
        $upper  = static fn (string $k): string => strtoupper((string) preg_replace('/\s+/u', '', $get($k)));
        $format = lang('Clients.errorFormat');

        $text = static function (string $key, int $min, int $max) use (&$errors, $get): void {
            $len = mb_strlen($get($key));

            if ($len < $min || $len > $max) {
                $errors[$key] = lang('Clients.errorLength', [$min, $max]);
            }
        };

        $text('name', 1, 200);
        $text('address_line1', 1, 200);
        $text('address_line2', 0, 200);
        $text('city', 1, 100);

        if (preg_match('/^[0-9A-Z -]{3,10}$/D', strtoupper($get('postal_code'))) !== 1) {
            $errors['postal_code'] = $format;
        }

        $country = $get('country');

        if (! in_array($country, self::COUNTRIES, true)) {
            $errors['country'] = lang('Clients.errorChoice');
        }

        $type  = IdentifierType::tryFrom($get('identifier_type'));
        $value = $upper('identifier_value');

        if ($type === null) {
            $errors['identifier_type'] = lang('Clients.errorChoice');
        } elseif ($type === IdentifierType::None) {
            if ($value !== '') {
                $errors['identifier_value'] = lang('Clients.errorIdentifierNone');
            }
        } else {
            $pattern = match ($type) {
                IdentifierType::LocalId => match ($country) {
                    'SK', 'CZ' => '/^\d{8}$/D',
                    'PL'       => '/^\d{10}$/D',
                    default    => '/^[A-Z0-9]{1,20}$/D',
                },
                IdentifierType::EuVat => '/^[A-Z]{2}[A-Z0-9]{2,12}$/D',
                default               => '/^[A-Za-z0-9 .\/-]{1,30}$/D',
            };
            $candidate = $type === IdentifierType::Other ? $get('identifier_value') : $value;

            if (preg_match($pattern, $candidate) !== 1) {
                $errors['identifier_value'] = $format;
            }
        }

        $vat = $upper('vat_id');

        if ($vat !== '' && preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/D', $vat) !== 1) {
            $errors['vat_id'] = $format;
        }

        if ($upper('tax_id') !== '' && preg_match('/^[A-Z0-9]{4,20}$/D', $upper('tax_id')) !== 1) {
            $errors['tax_id'] = $format;
        }

        $mode = BillingMode::tryFrom($get('billing_mode'));

        if ($mode === null) {
            $errors['billing_mode'] = lang('Clients.errorChoice');
        } elseif ($mode === BillingMode::EuB2b && $vat === '') {
            $errors['vat_id'] = lang('Clients.errorEuB2bVat');
        }

        $email = $get('invoice_email');

        if ($email === '' || preg_match('/[\x00-\x1F\x7F]/', (string) ($v['invoice_email'] ?? '')) === 1
                          || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['invoice_email'] = lang('Clients.errorEmail');
        }

        $ccError = CcEmails::validate(CcEmails::parse($get('cc_emails')));

        if ($ccError !== null) {
            $errors['cc_emails'] = $ccError;
        }

        // Zámerný strop 8 celých číslic pre hodinovú sadzbu; stĺpec je numeric(12,2) ako všetky peniaze (IN-02).
        if (preg_match('/^\d{1,8}([.,]\d{1,2})?$/D', (string) preg_replace('/\s+/u', '', $get('hourly_rate'))) !== 1) {
            $errors['hourly_rate'] = lang('Clients.errorRate');
        }

        $currencyActive ??= static fn (string $c): bool => (new CurrencyRepository())->isActive($c);

        if (! $currencyActive($get('currency'))) {
            $errors['currency'] = lang('Clients.errorChoice');
        }

        if ($get('doc_language') !== '' && DocLanguage::tryFrom($get('doc_language')) === null) {
            $errors['doc_language'] = lang('Clients.errorChoice');
        }

        if ($get('due_days') !== '' && (preg_match('/^\d{1,3}$/D', $get('due_days')) !== 1 || (int) $get('due_days') > 365)) {
            $errors['due_days'] = lang('Clients.errorDueDays');
        }

        if (mb_strlen($get('portal_name')) > 100) {
            $errors['portal_name'] = lang('Clients.errorLength', [0, 100]);
        }

        if ($get('portal_url_template') !== '') {
            $portalError = PortalUrlTemplate::validate($get('portal_url_template'));

            if ($portalError !== null) {
                $errors['portal_url_template'] = $portalError;
            }
        }

        return $errors;
    }
}
