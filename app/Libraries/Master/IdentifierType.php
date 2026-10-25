<?php

declare(strict_types=1);

namespace App\Libraries\Master;

enum IdentifierType: string
{
    case LocalId = 'local_id';
    case EuVat   = 'eu_vat';
    case Other   = 'other';
    case None    = 'none';

    public function label(): string
    {
        return match ($this) {
            self::LocalId => lang('Clients.typeLocalId'),
            self::EuVat   => lang('Clients.typeEuVat'),
            self::Other   => lang('Clients.typeOther'),
            self::None    => lang('Clients.typeNone'),
        };
    }
}
