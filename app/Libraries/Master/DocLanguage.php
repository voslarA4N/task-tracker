<?php

declare(strict_types=1);

namespace App\Libraries\Master;

enum DocLanguage: string
{
    case Sk = 'sk';
    case Pl = 'pl';
    case Cs = 'cs';
    case En = 'en';

    public function label(): string
    {
        return match ($this) {
            self::Sk => lang('Entities.langSk'),
            self::Pl => lang('Entities.langPl'),
            self::Cs => lang('Entities.langCs'),
            self::En => lang('Entities.langEn'),
        };
    }
}
