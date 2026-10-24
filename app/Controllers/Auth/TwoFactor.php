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
        $userId = (int) $user->id;
        $now    = service('clock')->now();

        $locked = $this->lockedResponse($user, $secret);

        if ($locked !== null) {
            return $locked;
        }

        $raw  = $this->request->getPost('code');
        $code = is_string($raw) ? (string) preg_replace('/\s+/', '', $raw) : '';
        $step = $svc->matchStep($secret, $code, null);

        if ($step === null) {
            return $this->registerSecondFactorFailure($user, 'setup', $secret);
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
        (new AuditLog())->record(AuthEvent::TotpEnrolled, true, $userId);

        return $this->completeLogin($user);
    }

    public function verify(): ResponseInterface
    {
        if (auth('session')->loggedIn()) {
            return redirect()->to('/');
        }

        $user = $this->pendingUserOrRedirect();

        if ($user instanceof RedirectResponse) {
            return $user;
        }

        if (! $this->hasTotp($user)) {
            return redirect()->to('/2fa/setup');
        }

        return $this->verifyPage(null, 200);
    }

    public function check(): ResponseInterface
    {
        if (auth('session')->loggedIn()) {
            return redirect()->to('/');
        }

        $user = $this->pendingUserOrRedirect();

        if ($user instanceof RedirectResponse) {
            return $user;
        }

        if (! $this->hasTotp($user)) {
            return redirect()->to('/2fa/setup');
        }

        $locked = $this->lockedResponse($user);

        if ($locked !== null) {
            return $locked;
        }

        $raw  = $this->request->getPost('code');
        $code = is_string($raw) ? (string) preg_replace('/\s+/', '', $raw) : '';

        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return $this->registerSecondFactorFailure($user, 'totp');
        }

        $row = db_connect()->table('user_security')->select('totp_secret_enc, totp_last_step')->where('user_id', $user->id)->get()->getRow();

        if ($row === null || $row->totp_secret_enc === null) {
            return $this->registerSecondFactorFailure($user, 'totp');
        }

        $svc  = new TotpService();
        $last = $row->totp_last_step === null ? null : (int) $row->totp_last_step;
        $step = $svc->matchStep($svc->decryptSecret((string) $row->totp_secret_enc), $code, $last);

        if ($step === null || ! $svc->claimStep((int) $user->id, $step)) {
            return $this->registerSecondFactorFailure($user, 'totp');
        }

        return $this->completeLogin($user);
    }

    /**
     * Ak je IP alebo ucet zamknuty, zapise pokus do dennika a vrati 429, inak null.
     */
    private function lockedResponse(User $user, ?string $setupSecret = null): ?ResponseInterface
    {
        $email = mb_strtolower((string) $user->email);
        $until = (new LockoutService())->lockedUntil($this->request->getIPAddress(), $email, service('clock')->now());

        if ($until === null) {
            return null;
        }

        (new AuditLog())->record(AuthEvent::LockedAttempt, false, (int) $user->id, $email);

        return $this->failurePage($user, $setupSecret, lang('Login.errorLocked'), 429);
    }

    /**
     * Spolocne spracovanie chybneho druheho faktora: pocitadlo zamku, dennik, e-mail pri zamku.
     */
    private function registerSecondFactorFailure(User $user, string $detail, ?string $setupSecret = null): ResponseInterface
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

        if ($lock->lockedUntil($ip, $email, $now) !== null) {
            return $this->failurePage($user, $setupSecret, lang('Login.errorLocked'), 429);
        }

        return $this->failurePage($user, $setupSecret, lang('TwoFactor.errorInvalidCode'), 422);
    }

    private function failurePage(User $user, ?string $setupSecret, string $error, int $status): ResponseInterface
    {
        return $setupSecret === null ? $this->verifyPage($error, $status) : $this->page($user, $setupSecret, $error, $status);
    }

    private function verifyPage(?string $error, int $status): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setBody(view('auth/totp_verify', [
                'title' => lang('TwoFactor.verifyTitle'),
                'error' => $error,
            ]));
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
