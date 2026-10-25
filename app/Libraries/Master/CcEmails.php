<?php

declare(strict_types=1);

namespace App\Libraries\Master;

/**
 * E-maily v kopii (CLI-07, T-03-24): delenie aj na CR/LF, validacia, limit.
 */
final class CcEmails
{
    public const MAX = 5;

    /**
     * @return list<string>
     */
    public static function parse(string $raw): array
    {
        $parts = preg_split('/[,;\r\n]+/', $raw) ?: [];
        $out   = [];

        foreach ($parts as $part) {
            $email = mb_strtolower(trim($part));

            if ($email !== '' && ! in_array($email, $out, true)) {
                $out[] = $email;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $emails
     *
     * @return string|null null = OK, inak preložená chyba
     */
    public static function validate(array $emails): ?string
    {
        if (count($emails) > self::MAX) {
            return lang('Clients.errorCcTooMany');
        }

        foreach ($emails as $email) {
            if (preg_match('/[\x00-\x1F\x7F]/', $email) === 1
                || mb_strlen($email) > 254
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return lang('Clients.errorCcInvalid', [trim((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $email))]);
            }
        }

        return null;
    }
}
