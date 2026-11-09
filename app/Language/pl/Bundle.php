<?php

declare(strict_types=1);

return [
    'navTitle'         => 'Paczka dla księgowej',
    'navHint'          => 'ZIP z plikami PDF dokumentów za okres',
    'title'            => 'Paczka dla księgowej',
    'intro'            => 'ZIP z plikami PDF faktur i faktur korygujących oraz listą dokumentów w CSV. Dokumenty są wybierane według daty wykonania usługi. Paczka nie zawiera zestawień pracy.',
    'backToMore'       => 'Wróć do Więcej',
    'entityLabel'      => 'Podmiot',
    'fromLabel'        => 'Od miesiąca',
    'toLabel'          => 'Do miesiąca',
    'rangeHint'        => 'Najwyżej 12 miesięcy.',
    'previewCounts'    => 'Dokumentów: {0}, w tym faktur: {1}, faktur korygujących: {2}',
    'previewCancelled' => 'w tym {0} stornowanych',
    'previewTurnover'  => 'Obrót za okres {0}',
    'empty'            => 'W wybranym okresie nie ma żadnych dokumentów.',
    'download'         => 'Pobierz paczkę',
    'preparing'        => 'Przygotowuję paczkę…',
    'externalNote'     => 'Faktury zewnętrzne (wystawione poza aplikacją) nie są w paczce.',
    'errorOrder'       => 'Miesiąc „Od” nie może być późniejszy niż miesiąc „Do”.',
    'errorTooLong'     => 'Okres może mieć najwyżej 12 miesięcy.',
    'errorRange'       => 'Okres musi mieścić się w przedziale od stycznia 2000 do grudnia 2100. Popraw miesiące i spróbuj ponownie.',
    'errorMissingPdf'  => 'Dla dokumentów ({0}) brakuje zapisanego PDF: {1}. Paczka nie została utworzona.',
    'errorTooLarge'    => 'Paczka jest zbyt duża. Wybierz krótszy okres.',
    'errorBuild'       => 'Nie udało się utworzyć paczki. Spróbuj krótszego okresu lub powtórz.',
];
