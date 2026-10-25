<?php

declare(strict_types=1);

namespace App\Libraries\Master;

enum BillingMode: string
{
    case Domestic = 'domestic';
    case EuB2b    = 'eu_b2b';
    case NonEu    = 'non_eu';

    public function label(): string
    {
        return match ($this) {
            self::Domestic => lang('Clients.modeDomestic'),
            self::EuB2b    => lang('Clients.modeEuB2b'),
            self::NonEu    => lang('Clients.modeNonEu'),
        };
    }
}
