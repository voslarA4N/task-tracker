<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Libraries\Auth\AuditLog;
use App\Libraries\Auth\AuthEvent;
use App\Libraries\Auth\LockoutService;
use App\Libraries\Auth\SecurityNotifier;
use App\Libraries\Auth\TrustedDeviceService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Models\UserIdentityModel;
use DateTimeImmutable;

final class Login extends BaseController
{
    /**
     * Fiktivny Argon2id hash (nahodny retazec): overuje sa pre neexistujuci e-mail,
     * aby cas odpovede neprezradil existenciu uctu.
     */
    public const DUMMY_HASH = '$argon2id$v=19$m=19456,t=2,p=1$aDhES1B0Uy5QeTEzd1g0TQ$rGloNQZcckvVgvQhcSkWvON7ZFZuiNAa0dKaLqI9bAk';

    public function show(): ResponseInterface
    {
        if (auth('session')->loggedIn()) {
            return redirect()->to('/');
        }

        return $this->page('', null, 200);
    }

    public function attempt(): ResponseInterface
    {
        $rawEmail = $this->request->getPost('email');
        $password = $this->request->getPost('password');
        $email    = is_string($rawEmail) ? mb_strtolower(trim($rawEmail)) : '';
        $password = is_string($password) ? $password : '';

        $valid = $this->validateData(
            ['email' => $email, 'password' => $password],
            [
                'email'    => ['rules' => ['required', 'valid_email', 'max_length[254]']],
                'password' => ['rules' => ['required', 'max_length[1024]']],
            ],
        );

        if (! $valid) {
            return $this->page($email, lang('Login.errorInvalid'), 422);
        }

        $auth = auth('session');

        if ($auth->loggedIn()) {
            return redirect()->to('/');
        }

        $authenticator = $auth->getAuthenticator();

        if ($authenticator instanceof Session && $authenticator->isPending()) {
            $auth->logout();
        }

        $identity = model(UserIdentityModel::class)->getIdentityBySecret(Session::ID_TYPE_EMAIL_PASSWORD, $email);
        $userId   = $identity === null ? null : (int) $identity->user_id;

        $now     = service('clock')->now();
        $ip      = $this->request->getIPAddress();
        $lockout = new LockoutService();
        $audit   = new AuditLog();

        $until = $lockout->lockedUntil($ip, $email, $now);

        if ($until !== null) {
            $audit->record(AuthEvent::LockedAttempt, false, $userId, $email);

            return $this->locked($email, $until, $now);
        }

        $result = null;

        if ($identity === null) {
            password_verify($password, self::DUMMY_HASH);
        } else {
            $result = $auth->attempt(['email' => $email, 'password' => $password]);
        }

        if ($result === null || ! $result->isOK()) {
            $audit->record(AuthEvent::LoginPasswordFail, false, $userId, $email);

            if ($lockout->registerFailure($ip, $email, $now)) {
                $audit->record(AuthEvent::Lockout, false, $userId, $email, 'account');

                if ($userId !== null) {
                    (new SecurityNotifier())->notify(
                        SecurityNotifier::ACCOUNT_LOCKED,
                        $email,
                        $this->userLocale($userId),
                        ['at' => $now, 'ip' => $ip, 'minutes' => LockoutService::LOCK_MINUTES],
                    );
                }
            }

            $until = $lockout->lockedUntil($ip, $email, $now);

            if ($until !== null) {
                return $this->locked($email, $until, $now);
            }

            return $this->page($email, lang('Login.errorInvalid'), 422);
        }

        $audit->record(AuthEvent::LoginPasswordOk, true, $userId);
        // Epocha v case hesla: zmena hesla alebo reset 2FA pocas pending zneplatni tuto relaciu (WR-02).
        session()->set([
            'pending_since' => $now->getTimestamp(),
            'pending_epoch' => (new TrustedDeviceService())->currentEpoch((int) $userId),
        ]);

        return redirect()->to('/2fa');
    }

    public function logout(): ResponseInterface
    {
        $user = auth('session')->user();

        if ($user === null) {
            $authenticator = auth('session')->getAuthenticator();
            $pending       = $authenticator instanceof Session && $authenticator->isPending()
                ? $authenticator->getPendingUser()
                : null;
            $user = $pending;
        }

        $userId   = $user === null ? null : (int) $user->id;
        $deviceId = session('device_id');
        $audit    = new AuditLog();
        $redirect = redirect()->to('/login');

        // O-1 = revoke: explicitne odhlasenie odvola aktualne dovernostne zariadenie a zmaze jeho cookie.
        if ($userId !== null && is_int($deviceId)) {
            $devices = new TrustedDeviceService();

            if ($devices->revoke($deviceId, $userId, 'logout', service('clock')->now())) {
                $audit->record(AuthEvent::DeviceRevoked, true, $userId, null, 'logout');
            }

            $redirect->setCookie($devices->expiredCookie());
        }

        $audit->record(AuthEvent::Logout, true, $userId);

        auth('session')->logout();
        session()->remove(['pending_since', 'pending_epoch']);

        return $redirect;
    }

    private function locked(string $email, DateTimeImmutable $until, DateTimeImmutable $now): ResponseInterface
    {
        $minutes = max(1, intdiv($until->getTimestamp() - $now->getTimestamp() + 59, 60));

        return $this->page($email, lang('Login.errorLocked'), 429, lang('Login.lockedFor', [$minutes]));
    }

    private function userLocale(int $userId): string
    {
        $row = db_connect()->table('user_security')->select('locale')->where('user_id', $userId)->get()->getRow();

        return $row === null ? 'sk' : (string) $row->locale;
    }

    private function page(string $email, ?string $error, int $status, ?string $lockedInfo = null): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setBody(view('auth/login', ['title' => lang('Login.title'), 'email' => $email, 'error' => $error, 'lockedInfo' => $lockedInfo]));
    }
}
