<?php

declare(strict_types=1);

namespace App\Libraries\Work;

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\Money;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Whitelist, validacia a normalizacia vstupu fakturacnej casti (ALC-01). Bez DB.
 * Server nikdy neprijima sumu, menu, invoice_id ani task_id.
 */
final class AllocationInput
{
    public const FIELDS = ['hours', 'billing_month', 'rate'];

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

        if ($out['billing_month'] === '') {
            $choice = $request->getPost('month_choice');
            $other  = $request->getPost('billing_month_other');

            $out['billing_month'] = match (is_string($choice) ? $choice : '') {
                'current' => BillingMonth::current(),
                'next'    => BillingMonth::next(BillingMonth::current()),
                'other'   => is_string($other) ? trim($other) : '',
                default   => '',
            };
        }

        return $out;
    }

    /**
     * @param array<string, string|null> $v surove hodnoty (fromPost)
     *
     * @return array<string, string>
     */
    public static function validate(array $v, bool $allowZeroHours = false): array
    {
        $errors = [];
        $plain  = static fn (string $k): string => (string) preg_replace('/[\s\x{00A0}]+/u', '', trim((string) ($v[$k] ?? '')));

        $hours = $plain('hours');

        if (
            preg_match('/^\d{1,5}([.,]\d{1,2})?$/D', $hours) !== 1
            || (! $allowZeroHours && ! Hours::isPositive(Hours::of($hours)))
        ) {
            $errors['hours'] = lang('Allocations.errorHours');
        }

        $month = trim((string) ($v['billing_month'] ?? ''));

        if (BillingMonth::parse($month) === null || ! BillingMonth::inRange($month)) {
            $bounds                  = BillingMonth::rangeBounds();
            $locale                  = service('request')->getLocale();
            $errors['billing_month'] = lang('Allocations.errorMonth', [
                BillingMonth::label($bounds['min'], $locale),
                BillingMonth::label($bounds['max'], $locale),
            ]);
        }

        if (preg_match('/^\d{1,8}([.,]\d{1,2})?$/D', $plain('rate')) !== 1) {
            $errors['rate'] = lang('Allocations.errorRate');
        } elseif (! isset($errors['hours']) && ! TaskInput::amountFits($hours, $plain('rate'))) {
            $errors['rate'] = lang('Allocations.errorAmount');
        }

        return $errors;
    }

    /**
     * @param array<string, string|null> $v
     *
     * @return array{hours: string, billing_month: string, rate: string}
     */
    public static function normalize(array $v): array
    {
        $rate = str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}]+/u', '', trim((string) ($v['rate'] ?? ''))));

        return [
            'hours'         => Hours::of(trim((string) ($v['hours'] ?? ''))),
            'billing_month' => trim((string) ($v['billing_month'] ?? '')),
            'rate'          => Money::of($rate)->toDecimalString(),
        ];
    }
}
