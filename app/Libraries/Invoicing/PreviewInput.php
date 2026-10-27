<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Work\BillingMonth;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Whitelist a validacia vstupu nahladu faktury mesiaca (GET). Bez DB, bez zapisu.
 */
final class PreviewInput
{
    public const FIELDS         = ['client', 'month', 'entity', 'issue_date', 'delivery_date', 'due_date'];
    private const RANGE_BACK    = 24;
    private const RANGE_FORWARD = 1;

    /**
     * Horna hranica datumu vystavenia: dnes + N dni (preklep roku na iPhone nesmie vytvorit nemennu buducu fakturu).
     */
    public const ISSUE_MAX_AHEAD_DAYS = 7;

    /**
     * Datum dodania musi lezat v okne fakturacneho mesiaca +- N mesiacov.
     */
    public const DELIVERY_MONTH_SLACK = 1;

    /**
     * Splatnost najviac N mesiacov po datume vystavenia.
     */
    public const DUE_MAX_AHEAD_MONTHS = 12;

    /**
     * @return array<string, string>
     */
    public static function fromGet(IncomingRequest $request): array
    {
        return self::pick(static fn (string $f): mixed => $request->getGet($f));
    }

    /**
     * @return array<string, string>
     */
    public static function fromPost(IncomingRequest $request): array
    {
        return self::pick(static fn (string $f): mixed => $request->getPost($f));
    }

    /**
     * @param callable(string): mixed $read
     *
     * @return array<string, string>
     */
    private static function pick(callable $read): array
    {
        $out = [];

        foreach (self::FIELDS as $field) {
            $value       = $read($field);
            $out[$field] = is_string($value) ? trim($value) : '';
        }

        return $out;
    }

    /**
     * @return array{min: string, max: string}
     */
    public static function monthBounds(): array
    {
        $current = BillingMonth::current();

        return [
            'min' => BillingMonth::addMonths($current, -self::RANGE_BACK),
            'max' => BillingMonth::addMonths($current, self::RANGE_FORWARD),
        ];
    }

    /**
     * @param array<string, string> $v
     *
     * @return array<string, string>
     */
    public static function validate(array $v): array
    {
        $errors = [];

        foreach (['client', 'entity'] as $field) {
            $value = $v[$field] ?? '';

            if ($value !== '' && preg_match('/^\d{1,18}$/D', $value) !== 1) {
                $errors[$field] = lang('Invoicing.errorSelection');
            }
        }

        $month = $v['month'] ?? '';

        if ($month !== '') {
            $bounds = self::monthBounds();

            if (BillingMonth::parse($month) === null || $month < $bounds['min'] || $month > $bounds['max']) {
                $locale          = service('request')->getLocale();
                $errors['month'] = lang('Invoicing.errorMonth', [
                    BillingMonth::label($bounds['min'], $locale),
                    BillingMonth::label($bounds['max'], $locale),
                ]);
            }
        }

        $clock = service('clock');

        foreach (['issue_date', 'delivery_date', 'due_date'] as $field) {
            $value = $v[$field] ?? '';

            if ($value !== '' && $clock->parseDate($value) === null) {
                $errors[$field] = lang('Invoicing.errorDates');
            }
        }

        if (
            ($v['issue_date'] ?? '') !== '' && ($v['due_date'] ?? '') !== ''
            && ! isset($errors['issue_date']) && ! isset($errors['due_date'])
            && $v['due_date'] < $v['issue_date']
        ) {
            $errors['due_date'] = lang('Invoicing.errorDates');
        }

        return $errors + self::boundErrors($v, $errors);
    }

    /**
     * Horne a mesacne hranice datumov (WR-02). Kontroluje iba polia, ktore uz presli formatom.
     *
     * @param array<string, string> $v
     * @param array<string, string> $errors
     *
     * @return array<string, string>
     */
    private static function boundErrors(array $v, array $errors): array
    {
        $clock  = service('clock');
        $out    = [];
        $issue  = ($v['issue_date'] ?? '') !== '' && ! isset($errors['issue_date']) ? $v['issue_date'] : null;
        $due    = ($v['due_date'] ?? '') !== '' && ! isset($errors['due_date']) ? $v['due_date'] : null;
        $deliv  = ($v['delivery_date'] ?? '') !== '' && ! isset($errors['delivery_date']) ? $v['delivery_date'] : null;
        $month  = ($v['month'] ?? '') !== '' && ! isset($errors['month']) ? $v['month'] : null;
        $maxDay = $clock->parseDate($clock->today())?->modify('+' . self::ISSUE_MAX_AHEAD_DAYS . ' days')->format('Y-m-d');

        if ($issue !== null && $maxDay !== null && $issue > $maxDay) {
            $out['issue_date'] = lang('Invoicing.errorIssueFuture', [self::ISSUE_MAX_AHEAD_DAYS]);
        }

        if ($deliv !== null && $month !== null) {
            $from = BillingMonth::addMonths($month, -self::DELIVERY_MONTH_SLACK);
            $to   = BillingMonth::addMonths($month, self::DELIVERY_MONTH_SLACK);

            if (substr($deliv, 0, 7) < $from || substr($deliv, 0, 7) > $to) {
                $out['delivery_date'] = lang('Invoicing.errorDeliveryRange');
            }
        }

        if ($issue !== null && $due !== null) {
            $limit = $clock->parseDate($issue)?->modify('+' . self::DUE_MAX_AHEAD_MONTHS . ' months')->format('Y-m-d');

            if ($limit !== null && $due > $limit) {
                $out['due_date'] = lang('Invoicing.errorDueFar');
            }
        }

        return $out;
    }
}
