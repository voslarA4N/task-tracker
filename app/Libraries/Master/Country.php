<?php

declare(strict_types=1);

namespace App\Libraries\Master;

enum Country: string
{
    case SK = 'SK';
    case PL = 'PL';
    case CZ = 'CZ';

    public function defaultCurrency(): Currency
    {
        return match ($this) {
            self::SK => Currency::EUR,
            self::PL => Currency::PLN,
            self::CZ => Currency::CZK,
        };
    }

    public function defaultLanguage(): DocLanguage
    {
        return match ($this) {
            self::SK => DocLanguage::Sk,
            self::PL => DocLanguage::Pl,
            self::CZ => DocLanguage::Cs,
        };
    }

    /**
     * @return list<VatRegime>
     */
    public function vatRegimes(): array
    {
        return match ($this) {
            self::SK => [VatRegime::SkNonVat],
            self::PL => [VatRegime::PlExempt1131, VatRegime::PlExempt1139],
            self::CZ => [VatRegime::CzNonVat],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SK => lang('Entities.countrySK'),
            self::PL => lang('Entities.countryPL'),
            self::CZ => lang('Entities.countryCZ'),
        };
    }
}
