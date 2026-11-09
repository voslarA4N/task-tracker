<?php

declare(strict_types=1);

return [
    'navTitle'         => 'Balíček pro účetní',
    'navHint'          => 'ZIP s PDF dokladů za období',
    'title'            => 'Balíček pro účetní',
    'intro'            => 'ZIP s PDF faktur a dobropisů a CSV seznamem dokladů. Doklady se vybírají podle data plnění. Výkazy práce balíček neobsahuje.',
    'backToMore'       => 'Zpět na Více',
    'entityLabel'      => 'Subjekt',
    'fromLabel'        => 'Od měsíce',
    'toLabel'          => 'Do měsíce',
    'rangeHint'        => 'Nejvýše 12 měsíců.',
    'previewCounts'    => '{0} dokladů: {1} faktur, {2} dobropisů',
    'previewCancelled' => 'z toho {0} stornovaných',
    'previewTurnover'  => 'Obrat za období {0}',
    'empty'            => 'Ve zvoleném období nejsou žádné doklady.',
    'download'         => 'Stáhnout balíček',
    'preparing'        => 'Připravuji balíček…',
    'externalNote'     => 'Externí faktury (vystavené mimo aplikaci) balíček neobsahuje.',
    'errorOrder'       => 'Měsíc „Od“ nesmí být později než měsíc „Do“.',
    'errorTooLong'     => 'Období může mít nejvýše 12 měsíců.',
    'errorRange'       => 'Období musí být v rozmezí leden 2000 až prosinec 2100. Upravte měsíce a zkuste to znovu.',
    'errorMissingPdf'  => 'Pro {0} dokladů chybí uložené PDF: {1}. Balíček nebyl vytvořen.',
    'errorTooLarge'    => 'Balíček je příliš velký. Zvolte kratší období.',
    'errorBuild'       => 'Balíček se nepodařilo vytvořit. Zkuste kratší období nebo to zopakujte.',
];
