<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Authentication\Actions\TotpAction;
use App\Controllers\BaseController;
use App\Libraries\Auth\AuditLog;
use App\Libraries\Auth\AuthEvent;
use App\Libraries\Auth\LockoutService;
use App\Libraries\Auth\SecurityNotifier;
use App\Libraries\Auth\TotpService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;

final class TwoFactor extends BaseController
{
    /**
     * Overenie kodu pri beznom prihlaseni (A8).
     */
    public const PENDING_TTL = 600;

    /**
     * Prve nastavenie 2FA a stranka zalohovych kodov (D-10, D-11).
     */
    public const ENROL_TTL = 1800;

    private const SETUP_SECRET_KEY = 'totp_setup_secret';

    public function index(): ResponseInterface
    {
        $user = $this->pendingUserOrRedirect();

        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return redirect()->to($this->hasTotp($user) ? '/2fa/verify' : '/2fa/setup');
    }

    public function setup(): ResponseInterface
    {
        $user = $this->pendingUserOrRedirect(self::ENROL_TTL);

        if ($user instanceof RedirectResponse) {
            return $user;
        }

        if ($this->hasTotp($user)) {
            return redirect()->to('/2fa/verify');
        }

        return $this->page($user, $this->setupSecret(), null, 200);
    }

    public function activate(): ResponseInterface
    {
        $user = $this->pendingUserOrRedirect(self::ENROL_TTL);

        if ($user instanceof RedirectResponse) {
            return $user;
        }

        if ($this->hasTotp($user)) {
            return redirect()->to('/2fa/verify');
        }

        $svc    = new TotpService();
        $secret = $this->setupSecret();
        $email  = mb_strtolower((string) $user->email);
        $userId = (int) $user->id;
        $now    = service('clock')->now();
        $ip     = $this->request->getIPAddress();
        $lock   = new LockoutService();
        $audit  = new AuditLog();

        $until = $lock->lockedUntil($ip, $email, $now);

        if ($until !== null) {
            $audit->record(AuthEvent::LockedAttempt, false, $userId, $email);

            return $this->page($user, $secret, lang('Login.errorLocked'), 429);
        }

        $raw  = $this->request->getPost('code');
        $code = is_string($raw) ? (string) preg_replace('/\s+/', '', $raw) : '';
        $step = $svc->matchStep($secret, $code, null);

        if ($step === null) {
            $audit->record(AuthEvent::LoginTotpFail, false, $userId, $email, 'setup');

            if ($lock->registerFailure($ip, $email, $now)) {
                $audit->record(AuthEvent::Lockout, false, $userId, $email, 'account');
                (new SecurityNotifier())->notify(
                    SecurityNotifier::ACCOUNT_LOCKED,
                    $email,
                    $this->userLocale($userId),
                    ['at' => $now, 'ip' => $ip, 'minutes' => LockoutService::LOCK_MINUTES],
                );
            }

            $until = $lock->lockedUntil($ip, $email, $now);

            if ($until !== null) {
                return $this->page($user, $secret, lang('Login.errorLocked'), 429);
            }

            return $this->page($user, $secret, lang('TwoFactor.errorInvalidCode'), 422);
        }

        $db = db_connect();
        $db->query(
            'UPDATE user_security SET totp_secret_enc = :enc:, totp_enabled_at = :now:, totp_last_step = :step:, updated_at = :now: WHERE user_id = :u: AND totp_enabled_at IS NULL',
            ['enc' => $svc->encryptSecret($secret), 'now' => $now->format('Y-m-d H:i:sP'), 'step' => $step, 'u' => $userId],
        );

        if ($db->affectedRows() !== 1) {
            return redirect()->to('/2fa');
        }

        session()->remove(self::SETUP_SECRET_KEY);
        $audit->record(AuthEvent::TotpEnrolled, true, $userId);

        return $this->completeLogin($user);
    }

    /**
     * Vrati cakajuceho pouzivatela alebo presmerovanie (bez pending / vyprsane okno).
     */
    private function pendingUserOrRedirect(int $maxAge = self::PENDING_TTL): RedirectResponse|User
    {
        $authenticator = auth('session')->getAuthenticator();
        $user          = $authenticator instanceof Session && $authenticator->isPending() ? $authenticator->getPendingUser() : null;

        if ($user === null) {
            return redirect()->to('/login');
        }

        $since = session('pending_since');

        if (! is_int($since) || service('clock')->now()->getTimestamp() - $since > $maxAge) {
            auth('session')->logout();
            session()->remove(['pending_since', self::SETUP_SECRET_KEY]);
            session()->setFlashdata('auth_info', lang('TwoFactor.pendingExpired'));

            return redirect()->to('/login');
        }

        return $user;
    }

    /**
     * Dokonci Shield akciu po overenom kode a prihlasi pouzivatela.
     */
    private function completeLogin(User $user): RedirectResponse
    {
        $authenticator = auth('session')->getAuthenticator();
        $identity      = model(UserIdentityModel::class)->getIdentityByType($user, TotpAction::TYPE);

        if (! $authenticator instanceof Session || $identity === null || ! $authenticator->checkAction($identity, (string) $identity->secret)) {
            return redirect()->to('/login');
        }

        if (ENVIRONMENT !== 'testing') {
            session()->regenerate(true);
        }

        session()->remove('pending_since');
        (new LockoutService())->clear($this->request->getIPAddress(), mb_strtolower((string) $user->email));
        (new AuditLog())->record(AuthEvent::LoginTotpOk, true, (int) $user->id);

        return redirect()->to('/');
    }

    private function hasTotp(User $user): bool
    {
        $row = db_connect()->table('user_security')->select('totp_enabled_at')->where('user_id', $user->id)->get()->getRow();

        return $row !== null && $row->totp_enabled_at !== null;
    }

    /**
     * Otvorene tajomstvo pre prebiehajuce nastavenie; v session iba sifrovane.
     */
    private function setupSecret(): string
    {
        $svc    = new TotpService();
        $stored = session(self::SETUP_SECRET_KEY);

        if (is_string($stored) && $stored !== '') {
            return $svc->decryptSecret($stored);
        }

        $secret = $svc->newSecret();
        session()->set(self::SETUP_SECRET_KEY, $svc->encryptSecret($secret));

        return $secret;
    }

    private function userLocale(int $userId): string
    {
        $row = db_connect()->table('user_security')->select('locale')->where('user_id', $userId)->get()->getRow();

        return $row === null ? 'sk' : (string) $row->locale;
    }

    private function page(User $user, string $secret, ?string $error, int $status): ResponseInterface
    {
        $svc = new TotpService();

        return $this->response
            ->setStatusCode($status)
            ->setBody(view('auth/totp_setup', [
                'title'     => lang('TwoFactor.setupTitle'),
                'qrSvg'     => $svc->qrSvg($svc->provisioningUri($secret, (string) $user->email)),
                'secretFmt' => $svc->formatSecretForDisplay($secret),
                'secret'    => $secret,
                'error'     => $error,
            ]));
    }
}
