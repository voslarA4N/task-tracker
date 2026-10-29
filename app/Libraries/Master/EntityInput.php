<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use App\Libraries\Delivery\SenderIdentity;
use App\Libraries\Delivery\SenderRejected;
use CodeIgniter\HTTP\IncomingRequest;

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
    public const BASE_UPDATE_FIELDS = ['name_internal', 'active_from', 'active_to'];
    public const SENDER_FIELDS      = ['mail_from_name', 'mail_from_email', 'mail_reply_to', 'mail_bcc'];
    public const IMMUTABLE_KEYS     = ['country', 'currency'];
    private const MIN_DATE          = '2000-01-01';
    private const OPTIONAL          = ['address_line2', 'regon', 'tax_id', 'vat_id', 'registry_note', 'phone'];

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
    public static function baseUpdateFromPost(IncomingRequest $request): array
    {
        return self::pick($request, self::BASE_UPDATE_FIELDS);
    }

    /**
     * Prazdne pole, ak POST neobsahuje ziadne pole odosielatela (stare formulare ich nemenia).
     * Prazdne pole formulara znamena predvolenu hodnotu (NULL, D-27), `mail_bcc_none` = '1' znamena bez BCC.
     *
     * @return array<string, string>
     */
    public static function senderFromPost(IncomingRequest $request): array
    {
        $out  = [];
        $post = $request->getPost();

        if (array_intersect([...self::SENDER_FIELDS, 'mail_bcc_none'], array_keys($post)) === []) {
            return [];
        }

        foreach (self::SENDER_FIELDS as $field) {
            $value       = $request->getPost($field);
            $out[$field] = is_string($value) ? trim($value) : '';
        }

        $out['mail_bcc_none'] = $request->getPost('mail_bcc_none') === '1' ? '1' : '';

        return $out;
    }

    /**
     * Prevod hodnot formulara na stlpce subjektu (CR-01, D-27): prazdne pole = NULL (predvolena hodnota),
     * prazdne BCC = NULL (predvolene BCC na e-mail subjektu), zaskrtnute „bez BCC“ = [] .
     *
     * @param array<string, string> $sender vystup senderFromPost
     *
     * @return array<string, string|null>
     */
    public static function senderForStorage(array $sender): array
    {
        if ($sender === []) {
            return [];
        }

        $bcc = trim($sender['mail_bcc'] ?? '');

        return [
            'mail_from_name'  => trim($sender['mail_from_name'] ?? ''),
            'mail_from_email' => trim($sender['mail_from_email'] ?? ''),
            'mail_reply_to'   => trim($sender['mail_reply_to'] ?? ''),
            'mail_bcc'        => ($sender['mail_bcc_none'] ?? '') === '1' ? '' : ($bcc === '' ? null : $bcc),
        ];
    }

    /**
     * Validacia identity odosielatela (D-27, T-07-27, T-07-28).
     *
     * @param array<string, string|null> $v
     *
     * @return array<string, string>
     */
    public static function validateSender(array $v): array
    {
        $errors = [];
        $name   = trim((string) ($v['mail_from_name'] ?? ''));

        if ($name !== '' && (mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1)) {
            $errors['mail_from_name'] = lang('Entities.errorLength', [1, 100]);
        }

        $from = trim((string) ($v['mail_from_email'] ?? ''));

        if ($from !== '') {
            try {
                SenderIdentity::assertAllowed($from);

                if (mb_strlen($from) > 254) {
                    $errors['mail_from_email'] = lang('Entities.errorSenderEmail');
                }
            } catch (SenderRejected $e) {
                $errors['mail_from_email'] = $e->reason === 'domain'
                    ? lang('Entities.errorSenderDomain', [implode(', ', SenderIdentity::allowedDomains())])
                    : lang('Entities.errorSenderEmail');
            }
        }

        $reply = trim((string) ($v['mail_reply_to'] ?? ''));

        if ($reply !== '' && (preg_match('/[\x00-\x1F\x7F]/', $reply) === 1 || mb_strlen($reply) > 254 || filter_var($reply, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['mail_reply_to'] = lang('Entities.errorSenderEmail');
        }

        $bcc = CcEmails::parse((string) ($v['mail_bcc'] ?? ''));

        if ($bcc !== [] && ($v['mail_bcc_none'] ?? '') === '1') {
            $errors['mail_bcc'] = lang('Entities.errorSenderBccConflict');
        } elseif (count($bcc) > CcEmails::MAX) {
            $errors['mail_bcc'] = lang('Entities.errorSenderBccMax', [CcEmails::MAX]);
        } elseif (($error = CcEmails::validate($bcc)) !== null) {
            $errors['mail_bcc'] = $error;
        }

        return $errors;
    }

    /**
     * True, ak POST obsahuje nemenný kľúč (aj prázdny): krajina a mena sa po vytvorení nemenia (D-02).
     */
    public static function hasImmutableKeys(IncomingRequest $request): bool
    {
        $post = $request->getPost();

        foreach (self::IMMUTABLE_KEYS as $key) {
            if (array_key_exists($key, $post)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string|null> $v
     *
     * @return array<string, string>
     */
    public static function validateBaseUpdate(array $v, string $firstValidFrom, string $today): array
    {
        $errors = [];
        $name   = trim((string) ($v['name_internal'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name_internal'] = lang('Entities.errorLength', [1, 100]);
        }

        $from = self::parseDate((string) ($v['active_from'] ?? ''));
        $max  = (service('clock')->parseDate($today) ?? service('clock')->now())->modify('+366 days')->format('Y-m-d');

        if ($from === null || $from < self::MIN_DATE || $from > $max) {
            $errors['active_from'] = lang('Entities.errorDateRange', [self::fmt(self::MIN_DATE), self::fmt($max)]);
        } elseif ($from < $firstValidFrom) {
            $errors['active_from'] = lang('Entities.errorActiveFromBeforeFirstVersion', [self::fmt($firstValidFrom)]);
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
        return service('clock')->parseDate($s) === null ? null : $s;
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
        $max  = (service('clock')->parseDate($today) ?? service('clock')->now())->modify('+366 days')->format('Y-m-d');

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
        $d = service('clock')->parseDate($iso);

        return $d === null ? $iso : $d->format('j. n. Y');
    }
}
