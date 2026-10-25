<?php

declare(strict_types=1);

namespace App\Libraries\Master;

/**
 * Vzor odkazu na portal uloh klienta (CLI-04, D-12). Len http/https s hostom a {id} prave raz.
 */
final class PortalUrlTemplate
{
    public const PLACEHOLDER = '{id}';
    public const MAX         = 500;

    /**
     * @return string|null null ak je vzor platny, inak prelozena chyba
     */
    public static function validate(string $tpl): ?string
    {
        $error = lang('Clients.errorPortalTemplate');
        $len   = mb_strlen($tpl);

        if ($len < 1 || $len > self::MAX || preg_match('/[\x00-\x20\x7F\s]/u', $tpl) === 1) {
            return $error;
        }

        if (substr_count($tpl, self::PLACEHOLDER) !== 1) {
            return $error;
        }

        $parts = parse_url(str_replace(self::PLACEHOLDER, '123', $tpl));

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === ''
                             || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return $error;
        }

        return null;
    }

    public static function render(string $tpl, string $taskId): string
    {
        return str_replace(self::PLACEHOLDER, rawurlencode($taskId), $tpl);
    }
}
