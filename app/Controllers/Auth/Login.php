<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Models\UserIdentityModel;

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

        if ($identity === null) {
            password_verify($password, self::DUMMY_HASH);

            return $this->page($email, lang('Login.errorInvalid'), 422);
        }

        $result = $auth->attempt(['email' => $email, 'password' => $password]);

        if (! $result->isOK()) {
            return $this->page($email, lang('Login.errorInvalid'), 422);
        }

        session()->set('pending_since', service('clock')->now()->getTimestamp());

        return redirect()->to('/2fa');
    }

    public function logout(): ResponseInterface
    {
        auth('session')->logout();
        session()->remove('pending_since');

        return redirect()->to('/login');
    }

    private function page(string $email, ?string $error, int $status): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setBody(view('auth/login', ['title' => lang('Login.title'), 'email' => $email, 'error' => $error]));
    }
}
