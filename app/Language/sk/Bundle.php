<?php

declare(strict_types=1);

return [
    'navTitle'         => 'Balík pre účtovníčku',
    'navHint'          => 'ZIP s PDF dokladov za obdobie',
    'title'            => 'Balík pre účtovníčku',
    'intro'            => 'ZIP s PDF faktúr a dobropisov a CSV zoznamom dokladov. Doklady sa vyberajú podľa dátumu dodania. Výkazy práce balík neobsahuje.',
    'backToMore'       => 'Späť na Viac',
    'entityLabel'      => 'Subjekt',
    'fromLabel'        => 'Od mesiaca',
    'toLabel'          => 'Do mesiaca',
    'rangeHint'        => 'Najviac 12 mesiacov.',
    'previewCounts'    => '{0} dokladov: {1} faktúr, {2} dobropisov',
    'previewCancelled' => 'z toho {0} stornovaných',
    'previewTurnover'  => 'Obrat za obdobie {0}',
    'empty'            => 'V zvolenom období nie sú žiadne doklady.',
    'download'         => 'Stiahnuť balík',
    'preparing'        => 'Pripravujem balík…',
    'externalNote'     => 'Externé faktúry (vystavené mimo aplikácie) balík neobsahuje.',
    'errorOrder'       => 'Mesiac „Od“ nesmie byť neskôr ako mesiac „Do“.',
    'errorTooLong'     => 'Obdobie môže mať najviac 12 mesiacov.',
    'errorRange'       => 'Obdobie musí byť v rozmedzí január 2000 až december 2100. Upravte mesiace a skúste to znova.',
    'errorMissingPdf'  => 'Pre {0} dokladov chýba uložené PDF: {1}. Balík sa nevytvoril.',
    'errorTooLarge'    => 'Balík je príliš veľký. Zvoľte kratšie obdobie.',
    'errorBuild'       => 'Balík sa nepodarilo vytvoriť. Skúste kratšie obdobie alebo to zopakujte.',
];
