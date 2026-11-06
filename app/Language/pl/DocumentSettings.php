<?php

declare(strict_types=1);

return [
    'navTitle'     => 'Dokumenty',
    'title'        => 'Ponowne generowanie dokumentów',
    'intro'        => 'Zestawienie pracy można wygenerować ponownie w dowolnym momencie. PDF faktury tylko w ciągu terminu od wystawienia.',
    'fieldLabel'   => 'Termin dla PDF faktury',
    'fieldHint'    => 'Liczba całkowita od 0 do 8760. 0 oznacza, że PDF faktury nie może być generowany ponownie. Zmiana dotyczy tylko faktur wystawionych po niej; już wystawione zachowują swój termin.',
    'fieldUnit'    => 'godzin',
    'save'         => 'Zapisz',
    'saved'        => 'Ustawienia zostały zapisane.',
    'errorInvalid' => 'Wpisz liczbę całkowitą od 0 do 8760.',
    'errorStale'   => 'Ustawienie zostało w międzyczasie zmienione przez kogoś innego. Zapisana wartość to {0} h, Twoja wartość pozostała w formularzu. Sprawdź ją i zapisz ponownie.',
    'back'         => 'Wróć',
];
