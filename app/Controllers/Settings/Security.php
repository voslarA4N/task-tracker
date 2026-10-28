<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Auth\AccountRecovery;
use App\Libraries\Auth\AuditLog;
use App\Libraries\Auth\AuthEvent;
use App\Libraries\Auth\BackupCodeService;
use App\Libraries\Auth\LockoutService;
use App\Libraries\Auth\SecurityNotifier;
use App\Libraries\Auth\TotpService;
use App\Libraries\Auth\TrustedDeviceService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Authentication\Passwords;
use CodeIgniter\Shield\Entities\User;
use Config\Auth;
use DateMalformedStringException;
use IntlDateFormatter;
use InvalidArgumentException;

final class Security extends BaseController
{
    private const EVENT_LIMIT = 50;
    private const CODES_KEY   = 'settings_codes_display';
    private const TXT_NAME    = 'work-tracker-zalozne-kody.txt';
    private const SETUP_KEY   = 'settings_totp_secret';

    public function index(): string
    {
        $user = auth()->user();
        assert($user instanceof User);

        return view('settings/security', $this->pageData($user));
    }

    public function revokeDevice(int $id): ResponseInterface
    {
        $user = auth()->user();
        assert($user instanceof User);
        $userId = (int) $user->id;

        $devices = new TrustedDeviceService();

        if (! $devices->revoke($id, $userId, 'user', service('clock')->now())) {
            throw PageNotFoundException::forPageNotFound();
        }

        (new AuditLog())->record(AuthEvent::DeviceRevoked, true, $userId, null, 'user');

        if ($id === session('device_id')) {
            auth('session')->logout();
            session()->setFlashdata('auth_info', lang('Account.revoked'));

            return redirect()->to('/login')->setCookie($devices->expiredCookie());
        }

        session()->setFlashdata('app_notice', ['type' => 'info', 'message' => lang('Account.revoked')]);

        return redirect()->to('/settings/security');
    }

    public function changePassword(): ResponseInterface
    {
        $user = $this->currentUser();

        $current = $this->postString('current_password');
        $new     = $this->postString('password');
        $confirm = $this->postString('password_confirm');

        if ($current === '' || $new === '' || mb_strlen($new) > 1024) {
            return $this->errorPage($user, 'passwordError', lang('Account.passwordRequired'), 422);
        }

        if ($new !== $confirm) {
            return $this->errorPage($user, 'passwordError', lang('Account.passwordMismatch'), 422);
        }

        $failed = $this->reauthenticate($user, $current, 'password');

        if ($failed !== null) {
            return $failed;
        }

        $now     = service('clock')->now();
        $devices = new TrustedDeviceService();

        try {
            (new AccountRecovery())->setPassword($user, $new, 'settings', $now);
        } catch (InvalidArgumentException $e) {
            return $this->errorPage($user, 'passwordError', $e->getMessage(), 422);
        }

        // Tato relacia ostava, ale uz ako nedoveryhodna (D-17).
        session()->remove('device_id');
        session()->set([
            'auth_epoch'    => $devices->currentEpoch((int) $user->id),
            'last_activity' => $now->getTimestamp(),
        ]);
        session()->setFlashdata('app_notice', ['type' => 'info', 'message' => lang('Account.passwordChanged')]);

        return redirect()->to('/settings/security')->setCookie($devices->expiredCookie());
    }

    public function regenerateCodes(): ResponseInterface
    {
        $user = $this->currentUser();

        if (! $this->hasTotp((int) $user->id)) {
            return redirect()->to('/settings/security');
        }

        $failed = $this->reauthenticate($user, $this->postString('current_password'), 'codes');

        if ($failed !== null) {
            return $failed;
        }

        $userId = (int) $user->id;
        $now    = service('clock')->now();
        $codes  = (new BackupCodeService())->generate($userId, $now);
        session()->set(self::CODES_KEY, (new TotpService())->encryptSecret((string) json_encode($codes)));

        (new AuditLog())->record(AuthEvent::BackupCodesRegenerated, true, $userId);
        (new SecurityNotifier())->notify(
            SecurityNotifier::BACKUP_CODES_REGENERATED,
            (string) $user->email,
            $this->userLocale($userId),
            ['at' => $now],
        );

        return redirect()->to('/settings/security/backup-codes');
    }

    public function showCodes(): ResponseInterface
    {
        $user  = $this->currentUser();
        $codes = $this->displayCodes();

        if ($codes === null || ! $this->hasTotp((int) $user->id)) {
            return redirect()->to('/settings/security');
        }

        return $this->codesPage($user, $codes, null, 200);
    }

    public function codesTxt(): ResponseInterface
    {
        $user  = $this->currentUser();
        $codes = $this->displayCodes();

        if ($codes === null) {
            return redirect()->to('/settings/security');
        }

        $text = "Work Tracker\n" . $user->email . "\n" . service('clock')->now()->format('Y-m-d') . "\n\n" . implode("\n", $codes) . "\n";

        return $this->response
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . self::TXT_NAME . '"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($text);
    }

    public function confirmCodes(): ResponseInterface
    {
        $user  = $this->currentUser();
        $codes = $this->displayCodes();

        if ($codes === null) {
            return redirect()->to('/settings/security');
        }

        if ($this->postString('acknowledged') !== '1') {
            return $this->codesPage($user, $codes, lang('TwoFactor.codesAckRequired'), 422);
        }

        session()->remove(self::CODES_KEY);
        session()->setFlashdata('app_notice', ['type' => 'info', 'message' => lang('Account.codesSaved')]);

        return redirect()->to('/settings/security');
    }

    /**
     * Zapnutie 2FA z nastaveni: zobrazi QR a tajomstvo (iba sifrovane v session).
     */
    public function setupTotp(): ResponseInterface
    {
        $user = $this->currentUser();

        if ($this->hasTotp((int) $user->id)) {
            return redirect()->to('/settings/security');
        }

        return $this->setupPage($user, $this->setupSecret(), null, 200);
    }

    public function enableTotp(): ResponseInterface
    {
        $user   = $this->currentUser();
        $userId = (int) $user->id;

        if ($this->hasTotp($userId)) {
            return redirect()->to('/settings/security');
        }

        $secret = $this->setupSecret();
        $failed = $this->passwordFailure($user, $this->postString('current_password'));

        if ($failed !== null) {
            return $this->setupPage($user, $secret, $failed[0], $failed[1]);
        }

        $svc  = new TotpService();
        $now  = service('clock')->now();
        $code = (string) preg_replace('/\s+/', '', $this->postString('code'));
        $step = $svc->matchStep($secret, $code, null);

        if ($step === null) {
            $failed = $this->codeFailure($user, 'enable');

            return $this->setupPage($user, $secret, $failed[0], $failed[1]);
        }

        $db = db_connect();
        $db->query(
            'UPDATE user_security SET totp_secret_enc = :enc:, totp_enabled_at = :now:, totp_last_step = :step:, totp_declined_at = NULL, updated_at = :now: WHERE user_id = :u: AND totp_enabled_at IS NULL',
            ['enc' => $svc->encryptSecret($secret), 'now' => $now->format('Y-m-d H:i:sP'), 'step' => $step, 'u' => $userId],
        );

        if ($db->affectedRows() !== 1) {
            return redirect()->to('/settings/security');
        }

        session()->remove(self::SETUP_KEY);
        (new AuditLog())->record(AuthEvent::TotpEnrolled, true, $userId, null, 'settings');

        // Zapnutie 2FA zneplatni ostatne (iba heslom chranene) relacie a dovernute zariadenia; tato relacia pokracuje.
        $devices = new TrustedDeviceService();
        $devices->revokeAll($userId, 'totp_enabled', $now);
        $devices->bumpEpoch($userId, $now);
        session()->remove('device_id');
        session()->set([
            'auth_epoch'    => $devices->currentEpoch($userId),
            'last_activity' => $now->getTimestamp(),
        ]);

        if (ENVIRONMENT !== 'testing') {
            session()->regenerate(true);
        }

        $codes = (new BackupCodeService())->generate($userId, $now);
        session()->set(self::CODES_KEY, $svc->encryptSecret((string) json_encode($codes)));
        (new SecurityNotifier())->notify(SecurityNotifier::TOTP_ENABLED, (string) $user->email, $this->userLocale($userId), ['at' => $now]);

        return redirect()->to('/settings/security/backup-codes')->setCookie($devices->expiredCookie());
    }

    /**
     * Vypnutie 2FA (D-03): sucasne heslo A kod z aplikacie alebo nepouzity zalozny kod.
     */
    public function disableTotp(): ResponseInterface
    {
        $user   = $this->currentUser();
        $userId = (int) $user->id;

        if (! $this->hasTotp($userId)) {
            return redirect()->to('/settings/security');
        }

        $email = mb_strtolower((string) $user->email);
        $now   = service('clock')->now();

        if ((new LockoutService())->lockedUntil($this->request->getIPAddress(), $email, $now) !== null) {
            (new AuditLog())->record(AuthEvent::LockedAttempt, false, $userId, $email);

            return $this->errorPage($user, 'disableError', lang('Login.errorLocked'), 429);
        }

        $failed = $this->reauthenticate($user, $this->postString('current_password'), 'disable');

        if ($failed !== null) {
            return $failed;
        }

        $input = trim($this->postString('code'));
        $plain = (string) preg_replace('/\s+/', '', $input);

        if (preg_match('/^[0-9]{6}$/', $plain) === 1) {
            $valid = $this->claimCurrentTotp($userId, $plain);
        } else {
            $valid = $input !== '' && (new BackupCodeService())->consume($userId, $input, $now);
        }

        if (! $valid) {
            $failed = $this->codeFailure($user, 'disable');

            return $this->errorPage($user, 'disableError', $failed[0], $failed[1]);
        }

        (new AccountRecovery())->disableTwoFactor($user, $now);

        $devices = new TrustedDeviceService();
        session()->remove('device_id');
        session()->set([
            'auth_epoch'    => $devices->currentEpoch($userId),
            'last_activity' => $now->getTimestamp(),
        ]);

        if (ENVIRONMENT !== 'testing') {
            session()->regenerate(true);
        }

        session()->setFlashdata('app_notice', ['type' => 'info', 'message' => lang('Account.twofaDisabledNotice')]);

        return redirect()->to('/settings/security')->setCookie($devices->expiredCookie());
    }

    private function claimCurrentTotp(int $userId, string $code): bool
    {
        $row = db_connect()->table('user_security')->select('totp_secret_enc, totp_last_step')->where('user_id', $userId)->get()->getRow();

        if ($row === null || $row->totp_secret_enc === null) {
            return false;
        }

        $svc  = new TotpService();
        $last = $row->totp_last_step === null ? null : (int) $row->totp_last_step;
        $step = $svc->matchStep($svc->decryptSecret((string) $row->totp_secret_enc), $code, $last);

        return $step !== null && $svc->claimStep($userId, $step);
    }

    /**
     * Chybny kod: dennik, pocitadlo zamku, e-mail pri zamku. Vrati [sprava, stav].
     *
     * @return array{0: string, 1: int}
     */
    private function codeFailure(User $user, string $detail): array
    {
        $email  = mb_strtolower((string) $user->email);
        $userId = (int) $user->id;
        $now    = service('clock')->now();
        $ip     = $this->request->getIPAddress();
        $lock   = new LockoutService();
        $audit  = new AuditLog();

        $audit->record(AuthEvent::LoginTotpFail, false, $userId, $email, $detail);

        if ($lock->registerFailure($ip, $email, $now)) {
            $audit->record(AuthEvent::Lockout, false, $userId, $email, 'account');
            (new SecurityNotifier())->notify(
                SecurityNotifier::ACCOUNT_LOCKED,
                $email,
                $this->userLocale($userId),
                ['at' => $now, 'ip' => $ip, 'minutes' => LockoutService::LOCK_MINUTES],
            );
        }

        if ($lock->lockedUntil($ip, $email, service('clock')->now()) !== null) {
            return [lang('Login.errorLocked'), 429];
        }

        return [lang('TwoFactor.errorInvalidCode'), 422];
    }

    private function hasTotp(int $userId): bool
    {
        $row = db_connect()->table('user_security')->select('totp_enabled_at')->where('user_id', $userId)->get()->getRow();

        return $row !== null && $row->totp_enabled_at !== null;
    }

    private function setupSecret(): string
    {
        $svc    = new TotpService();
        $stored = session(self::SETUP_KEY);

        if (is_string($stored) && $stored !== '') {
            return $svc->decryptSecret($stored);
        }

        $secret = $svc->newSecret();
        session()->set(self::SETUP_KEY, $svc->encryptSecret($secret));

        return $secret;
    }

    private function setupPage(User $user, string $secret, ?string $error, int $status): ResponseInterface
    {
        $svc = new TotpService();

        return $this->response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setBody(view('settings/totp_setup', [
                'title'     => lang('Account.enableTitle'),
                'qrSvg'     => $svc->qrSvg($svc->provisioningUri($secret, (string) $user->email)),
                'secretFmt' => $svc->formatSecretForDisplay($secret),
                'secret'    => $secret,
                'error'     => $error,
            ]));
    }

    /**
     * Opatovne overenie sucasnym heslom (D-19): null pri uspechu, inak 429 (zamok) alebo 422.
     *
     * @param string $form password, codes alebo disable (ktora karta ukaze chybu)
     */
    private function reauthenticate(User $user, string $password, string $form): ?ResponseInterface
    {
        $failed = $this->passwordFailure($user, $password);

        if ($failed === null) {
            return null;
        }

        $key = match ($form) {
            'password' => 'passwordError',
            'disable'  => 'disableError',
            default    => 'codesError',
        };

        return $this->errorPage($user, $key, $failed[0], $failed[1]);
    }

    /**
     * Kontrola sucasneho hesla so zamkom a dennikom: null pri uspechu, inak [sprava, stav].
     *
     * @return array{0: string, 1: int}|null
     */
    private function passwordFailure(User $user, string $password): ?array
    {
        $email  = mb_strtolower((string) $user->email);
        $userId = (int) $user->id;
        $now    = service('clock')->now();
        $ip     = $this->request->getIPAddress();
        $lock   = new LockoutService();
        $audit  = new AuditLog();

        if ($lock->lockedUntil($ip, $email, $now) !== null) {
            $audit->record(AuthEvent::LockedAttempt, false, $userId, $email);

            return [lang('Login.errorLocked'), 429];
        }

        $hash = (string) $user->password_hash;

        if ($password !== '' && $hash !== '' && (new Passwords(config(Auth::class)))->verify($password, $hash)) {
            return null;
        }

        $audit->record(AuthEvent::LoginPasswordFail, false, $userId, $email, 'reauth');

        if ($lock->registerFailure($ip, $email, $now)) {
            $audit->record(AuthEvent::Lockout, false, $userId, $email, 'account');
            (new SecurityNotifier())->notify(
                SecurityNotifier::ACCOUNT_LOCKED,
                $email,
                $this->userLocale($userId),
                ['at' => $now, 'ip' => $ip, 'minutes' => LockoutService::LOCK_MINUTES],
            );
        }

        if ($lock->lockedUntil($ip, $email, service('clock')->now()) !== null) {
            return [lang('Login.errorLocked'), 429];
        }

        return [lang('Account.reauthFailed'), 422];
    }

    private function errorPage(User $user, string $key, string $message, int $status): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setBody(view('settings/security', $this->pageData($user) + [$key => $message]));
    }

    /**
     * @param list<string> $codes
     */
    private function codesPage(User $user, array $codes, ?string $error, int $status): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setBody(view('settings/backup_codes', [
                'title'       => lang('Account.codesTitle'),
                'codes'       => $codes,
                'email'       => (string) $user->email,
                'downloadUrl' => '/settings/security/backup-codes.txt',
                'error'       => $error,
            ]));
    }

    /**
     * @return list<string>|null
     */
    private function displayCodes(): ?array
    {
        $stored = session(self::CODES_KEY);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        $decoded = json_decode((new TotpService())->decryptSecret($stored), true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : null;
    }

    private function currentUser(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function postString(string $name): string
    {
        $raw = $this->request->getPost($name);

        return is_string($raw) ? $raw : '';
    }

    private function userLocale(int $userId): string
    {
        $row = db_connect()->table('user_security')->select('locale')->where('user_id', $userId)->get()->getRow();

        return $row === null ? 'sk' : (string) $row->locale;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageData(User $user): array
    {
        $userId  = (int) $user->id;
        $now     = service('clock')->now();
        $devices = [];

        foreach ((new TrustedDeviceService())->listForUser($userId, $now) as $device) {
            $devices[] = [
                'id'      => (int) $device['id'],
                'label'   => (string) $device['label'],
                'created' => $this->formatTime((string) $device['created_at']),
                'last'    => $this->formatTime((string) $device['last_used_at']),
                'expires' => $this->formatTime((string) $device['expires_at']),
            ];
        }

        $events = [];

        foreach ((new AuditLog())->latestForUser($userId, self::EVENT_LIMIT) as $event) {
            $locked   = in_array($event['event'], ['lockout', 'locked_attempt'], true);
            $statusOk = $event['success'] && ! $locked;
            $events[] = [
                'time'      => $this->formatTime($event['occurred_at']),
                'ok'        => $statusOk,
                'statusKey' => $statusOk ? 'statusOk' : ($locked ? 'statusLocked' : 'statusFailed'),
                'reasonKey' => 'event_' . $event['event'],
                'ip'        => $event['ip'],
                'device'    => $event['device_label'],
            ];
        }

        $deviceId = session('device_id');

        return [
            'title'           => lang('Account.securityTitle'),
            'totpActive'      => $this->hasTotp($userId),
            'remainingCodes'  => (new BackupCodeService())->remaining($userId),
            'devices'         => $devices,
            'currentDeviceId' => is_int($deviceId) ? $deviceId : null,
            'events'          => $events,
        ];
    }

    private function formatTime(string $timestamp): string
    {
        try {
            $unix = service('clock')->now()->modify($timestamp)->getTimestamp();
        } catch (DateMalformedStringException) {
            return $timestamp;
        }

        $formatter = new IntlDateFormatter(
            service('request')->getLocale(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            config('App')->appTimezone,
            IntlDateFormatter::GREGORIAN,
            'd. M. yyyy HH:mm',
        );
        $out = $formatter->format($unix);

        return $out === false ? $timestamp : $out;
    }
}
