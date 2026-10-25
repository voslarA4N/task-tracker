<?php

declare(strict_types=1);

namespace App\Libraries\Work;

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\Money;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Whitelist, validacia a normalizacia vstupu ulohy (TSK-01). Bez DB.
 * Server nikdy neprijima sumu, stav, done_at, invoice_id ani import_key.
 */
final class TaskInput
{
    public const FIELDS = ['client_id', 'url', 'title', 'hours', 'rate', 'note'];

    /**
     * @return array<string, string>
     */
    public static function fromPost(IncomingRequest $request): array
    {
        $out = [];

        foreach (self::FIELDS as $field) {
            $value       = $request->getPost($field);
            $out[$field] = is_string($value) ? trim($value) : '';
        }

        return $out;
    }

    /**
     * @param array<string, string|null> $v surove hodnoty (fromPost)
     *
     * @return array<string, string>
     */
    public static function validate(array $v, ?string $portalTemplate = null): array
    {
        $errors = [];
        $get    = static fn (string $k): string => trim((string) ($v[$k] ?? ''));
        $plain  = static fn (string $k): string => (string) preg_replace('/[\s\x{00A0}]+/u', '', $get($k));

        if (preg_match('/^\d{1,18}$/D', $get('client_id')) !== 1) {
            $errors['client_id'] = lang('Tasks.errorClient');
        }

        $titleLength = mb_strlen($get('title'));

        if ($titleLength < 1 || $titleLength > 300) {
            $errors['title'] = lang('Tasks.errorTitle');
        }

        $hours = $plain('hours');

        if (preg_match('/^\d{1,5}([.,]\d{1,2})?$/D', $hours) !== 1 || ! Hours::isPositive(Hours::of($hours))) {
            $errors['hours'] = lang('Tasks.errorHours');
        }

        if (preg_match('/^\d{1,8}([.,]\d{1,2})?$/D', $plain('rate')) !== 1) {
            $errors['rate'] = lang('Tasks.errorRate');
        }

        if (! self::urlIsValid($get('url'), $portalTemplate)) {
            $errors['url'] = lang('Tasks.errorUrl');
        }

        if (mb_strlen($get('note')) > 2000) {
            $errors['note'] = lang('Tasks.errorNote');
        }

        return $errors;
    }

    /**
     * @param array<string, string|null> $v
     *
     * @return array{client_id: int, url: ?string, title: string, hours: string, rate: string, note: ?string}
     */
    public static function normalize(array $v): array
    {
        $get  = static fn (string $k): string => trim((string) ($v[$k] ?? ''));
        $rate = str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}]+/u', '', $get('rate')));

        return [
            'client_id' => (int) $get('client_id'),
            'url'       => $get('url') === '' ? null : $get('url'),
            'title'     => $get('title'),
            'hours'     => Hours::of($get('hours')),
            'rate'      => Money::of($rate)->toDecimalString(),
            'note'      => $get('note') === '' ? null : $get('note'),
        ];
    }

    private static function urlIsValid(string $url, ?string $portalTemplate): bool
    {
        if ($url === '') {
            return true;
        }

        if (preg_match('/^\d{1,20}$/D', $url) === 1) {
            return $portalTemplate !== null;
        }

        if (mb_strlen($url) > 2000 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && (string) ($parts['host'] ?? '') !== '';
    }
}
