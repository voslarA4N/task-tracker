<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use InvalidArgumentException;

/**
 * Kotviaci datum a horna hranica pre vyhladanie kurzu (D-05). Iba kalendarna aritmetika, bez sviatkov (D-06).
 */
final class FxBoundDate
{
    public static function anchor(string $dateBasis, string $issueDate, string $deliveryDate): string
    {
        return match ($dateBasis) {
            'delivery_date' => $deliveryDate,
            'issue_date'    => $issueDate,
            default         => throw new InvalidArgumentException('Unknown date basis.'),
        };
    }

    public static function bound(string $dayRule, string $anchor): string
    {
        $date = service('clock')->parseDate($anchor);

        if ($date === null) {
            throw new InvalidArgumentException('Invalid anchor date.');
        }

        return match ($dayRule) {
            'previous_business_day' => $date->modify('-1 day')->format('Y-m-d'),
            'on_or_before'          => $date->format('Y-m-d'),
            default                 => throw new InvalidArgumentException('Unknown day rule.'),
        };
    }
}
