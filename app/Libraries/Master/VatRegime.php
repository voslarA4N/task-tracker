<?php

declare(strict_types=1);

namespace App\Libraries\Master;

enum VatRegime: string
{
    case SkNonVat     = 'sk_non_vat';
    case PlExempt1131 = 'pl_exempt_113_1';
    case PlExempt1139 = 'pl_exempt_113_9';
    case CzNonVat     = 'cz_non_vat';

    public function country(): Country
    {
        return match ($this) {
            self::SkNonVat                         => Country::SK,
            self::PlExempt1131, self::PlExempt1139 => Country::PL,
            self::CzNonVat                         => Country::CZ,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SkNonVat     => lang('Entities.regimeSkNonVat'),
            self::PlExempt1131 => lang('Entities.regimePlExempt1131'),
            self::PlExempt1139 => lang('Entities.regimePlExempt1139'),
            self::CzNonVat     => lang('Entities.regimeCzNonVat'),
        };
    }
}
