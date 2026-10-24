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

        if ($codes === null) {
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
     * Opatovne overenie sucasnym heslom (D-19): null pri uspechu, inak 429 (zamok) alebo 422.
     *
     * @param string $form password alebo codes (ktora karta ukaze chybu)
     */
    private function reauthenticate(User $user, string $password, string $form): ?ResponseInterface
    {
        $email  = mb_strtolower((string) $user->email);
        $userId = (int) $user->id;
        $now    = service('clock')->now();
        $ip     = $this->request->getIPAddress();
        $lock   = new LockoutService();
        $audit  = new AuditLog();
        $key    = $form === 'password' ? 'passwordError' : 'codesError';

        if ($lock->lockedUntil($ip, $email, $now) !== null) {
            $audit->record(AuthEvent::LockedAttempt, false, $userId, $email);

            return $this->errorPage($user, $key, lang('Login.errorLocked'), 429);
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
            return $this->errorPage($user, $key, lang('Login.errorLocked'), 429);
        }

        return $this->errorPage($user, $key, lang('Account.reauthFailed'), 422);
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
