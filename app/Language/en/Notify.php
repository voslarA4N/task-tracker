<?php

declare(strict_types=1);

return [
    'subject_password_changed'         => 'Work Tracker: password changed',
    'subject_password_reset'           => 'Work Tracker: password reset',
    'subject_totp_reset'               => 'Work Tracker: two-factor authentication reset',
    'subject_totp_enabled'             => 'Work Tracker: two-factor authentication turned on',
    'subject_totp_disabled'            => 'Work Tracker: two-factor authentication turned off',
    'subject_backup_codes_regenerated' => 'Work Tracker: new backup codes',
    'subject_device_trusted'           => 'Work Tracker: new trusted device',
    'subject_account_locked'           => 'Work Tracker: account temporarily locked',
    'body_password_changed'            => 'The password for your account was changed.',
    'body_password_reset'              => 'The password for your account was reset.',
    'body_totp_reset'                  => 'Two-factor authentication for your account was reset.',
    'body_totp_enabled'                => 'Two-factor authentication for your account was turned on.',
    'body_totp_disabled'               => 'Two-factor authentication for your account was turned off. If this was not you, change your password immediately.',
    'body_backup_codes_regenerated'    => 'Backup codes for your account were regenerated; the previous ones no longer work.',
    'body_device_trusted'              => 'A device was marked as trusted to skip the 2FA code.',
    'body_account_locked'              => 'The account was temporarily locked after repeated failed sign-in attempts.',
    'labelTime'                        => 'Time',
    'labelIp'                          => 'IP address',
    'labelDevice'                      => 'Device',
    'lockedFor'                        => 'The lock lasts {minutes} minutes.',
    'notYou'                           => 'If this was not you, change your password in Settings → Security or contact the server administrator.',
    'signature'                        => 'Work Tracker',
];
