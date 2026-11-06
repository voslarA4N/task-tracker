<?php

declare(strict_types=1);

return [
    'subject_password_changed'         => 'Work Tracker: zmiana hasła',
    'subject_password_reset'           => 'Work Tracker: reset hasła',
    'subject_totp_reset'               => 'Work Tracker: reset weryfikacji dwuetapowej',
    'subject_totp_enabled'             => 'Work Tracker: włączona weryfikacja dwuetapowa',
    'subject_totp_disabled'            => 'Work Tracker: wyłączona weryfikacja dwuetapowa',
    'subject_backup_codes_regenerated' => 'Work Tracker: nowe kody zapasowe',
    'subject_device_trusted'           => 'Work Tracker: nowe zaufane urządzenie',
    'subject_account_locked'           => 'Work Tracker: konto jest tymczasowo zablokowane',
    'body_password_changed'            => 'Hasło do Twojego konta zostało zmienione.',
    'body_password_reset'              => 'Hasło do Twojego konta zostało zresetowane przez odzyskiwanie hasła.',
    'body_totp_reset'                  => 'Weryfikacja dwuetapowa Twojego konta została zresetowana.',
    'body_totp_enabled'                => 'Weryfikacja dwuetapowa Twojego konta została włączona.',
    'body_totp_disabled'               => 'Weryfikacja dwuetapowa Twojego konta została wyłączona. Jeśli to nie Ty, natychmiast zmień hasło.',
    'body_backup_codes_regenerated'    => 'Kody zapasowe do Twojego konta zostały wygenerowane ponownie, poprzednie przestały obowiązywać.',
    'body_device_trusted'              => 'Urządzenie zostało oznaczone jako zaufane w celu pominięcia kodu 2FA.',
    'body_account_locked'              => 'Konto zostało tymczasowo zablokowane po wielokrotnych nieudanych próbach logowania.',
    'labelTime'                        => 'Czas',
    'labelIp'                          => 'Adres IP',
    'labelDevice'                      => 'Urządzenie',
    'lockedFor'                        => 'Blokada trwa {minutes} minut.',
    'notYou'                           => 'Jeśli to nie Ty, zmień hasło w Ustawienia → Zabezpieczenia lub skontaktuj się z administratorem serwera.',
    'signature'                        => 'Work Tracker',
];
