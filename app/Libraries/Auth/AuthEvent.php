<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

enum AuthEvent: string
{
    case LoginPasswordOk        = 'login_password_ok';
    case LoginPasswordFail      = 'login_password_fail';
    case LoginTotpOk            = 'login_totp_ok';
    case LoginTotpFail          = 'login_totp_fail';
    case BackupCodeUsed         = 'backup_code_used';
    case Lockout                = 'lockout';
    case LockedAttempt          = 'locked_attempt';
    case TotpEnrolled           = 'totp_enrolled';
    case BackupCodesRegenerated = 'backup_codes_regenerated';
    case DeviceTrusted          = 'device_trusted';
    case DeviceLogin            = 'device_login';
    case DeviceRevoked          = 'device_revoked';
    case Logout                 = 'logout';
    case IdleLogout             = 'idle_logout';
    case PasswordChanged        = 'password_changed';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetDone      = 'password_reset_done';
    case TotpResetCli           = 'totp_reset_cli';
    case PasswordSetCli         = 'password_set_cli';
    case UserCreatedCli         = 'user_created_cli';
    case TotpDeclined           = 'totp_declined';
    case TotpDisabled           = 'totp_disabled';
    case LoginNoTotp            = 'login_no_totp';
}
