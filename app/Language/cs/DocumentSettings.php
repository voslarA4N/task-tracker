<?php

declare(strict_types=1);

return [
    'navTitle'     => 'Doklady',
    'title'        => 'Přegenerování dokladů',
    'intro'        => 'Výkaz práce lze přegenerovat kdykoli. PDF faktury pouze v lhůtě od vystavení.',
    'fieldLabel'   => 'Lhůta pro PDF faktury',
    'fieldHint'    => 'Celé číslo 0 až 8760. 0 znamená, že PDF faktury nelze přegenerovat. Změna platí jen pro faktury vystavené po ní; již vystavené si ponechají svou lhůtu.',
    'fieldUnit'    => 'hodin',
    'save'         => 'Uložit',
    'saved'        => 'Nastavení bylo uloženo.',
    'errorInvalid' => 'Zadejte celé číslo od 0 do 8760.',
    'errorStale'   => 'Nastavení mezitím změnil někdo jiný. Uložená hodnota je {0} h, vaše zadaná hodnota zůstala ve formuláři. Zkontrolujte ji a uložte znovu.',
    'back'         => 'Zpět',
];
