<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Libraries\Auth\AccountRecovery;
use App\Libraries\Auth\AuditLog;
use App\Libraries\Auth\AuthEvent;
use App\Libraries\Auth\PasswordResetService;
use App\Libraries\Mail\AppMailer;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use InvalidArgumentException;

/**
 * Obnova hesla e-mailom (AUTH-06). Nikdy nevola login ani akcie: po zmene hesla sa vyzaduje heslo aj TOTP.
 */
final class PasswordReset extends BaseController
{
    public function forgot(): ResponseInterface
    {
        return $this->forgotPage('', false, 200);
    }

    public function send(): ResponseInterface
    {
        $raw   = $this->request->getPost('email');
        $email = is_string($raw) ? mb_strtolower(trim($raw)) : '';

        $valid = $this->validateData(['email' => $email], ['email' => ['rules' => ['required', 'valid_email', 'max_length[254]']]]);

        if (! $valid) {
            return $this->forgotPage($email, false, 422, lang('Login.errorEmailFormat'));
        }

        $now   = service('clock')->now();
        $token = (new PasswordResetService())->request($email, $this->request->getIPAddress(), $now);

        if ($token !== null) {
            $link = rtrim(config('App')->baseURL, '/') . '/password/reset/' . $token;
            $body = view('emails/password_reset', [
                'link'    => $link,
                'minutes' => PasswordResetService::TTL_MINUTES,
                'locale'  => $this->request->getLocale(),
            ], ['saveData' => false]);

            (new AppMailer())->send($email, lang('PasswordReset.mailSubject'), $body, 'password_reset');
            (new AuditLog())->record(AuthEvent::PasswordResetRequested, true, $this->userId($email));
        }

        // Vzdy rovnaka odpoved: neprezradi, ci ucet existuje.
        return $this->forgotPage('', true, 200);
    }

    public function edit(string $token): ResponseInterface
    {
        if ((new PasswordResetService())->findValid($token, service('clock')->now()) === null) {
            return $this->resetPage($token, true, null, 200);
        }

        return $this->resetPage($token, false, null, 200);
    }

    public function update(string $token): ResponseInterface
    {
        $service = new PasswordResetService();
        $now     = service('clock')->now();

        $rawPassword = $this->request->getPost('password');
        $rawConfirm  = $this->request->getPost('password_confirm');
        $password    = is_string($rawPassword) ? $rawPassword : '';
        $confirm     = is_string($rawConfirm) ? $rawConfirm : '';

        $userId = $service->findValid($token, $now);

        if ($userId === null) {
            return $this->resetPage($token, true, null, 200);
        }

        $valid = $this->validateData(
            ['password' => $password, 'password_confirm' => $confirm],
            [
                'password'         => ['rules' => ['required', 'max_length[1024]']],
                'password_confirm' => ['rules' => ['required', 'matches[password]']],
            ],
        );

        if (! $valid) {
            return $this->resetPage($token, false, lang('PasswordReset.errorMismatch'), 422);
        }

        $user = model(UserModel::class)->findById($userId);

        if (! $user instanceof User) {
            return $this->resetPage($token, true, null, 200);
        }

        $recovery = new AccountRecovery();

        // Slabe heslo nesmie spotrebovat token.
        try {
            $recovery->assertPasswordAcceptable($user, $password);
        } catch (InvalidArgumentException $e) {
            return $this->resetPage($token, false, $e->getMessage(), 422);
        }

        if ($service->consume($token, $now) === null) {
            return $this->resetPage($token, true, null, 200);
        }

        $recovery->setPassword($user, $password, 'reset', $now);

        // Nikdy login(): po resete je potrebne heslo aj TOTP.
        $current = auth('session')->user();

        if ($current !== null && (int) $current->id === $userId) {
            auth('session')->logout();
        }

        session()->setFlashdata('auth_info', lang('PasswordReset.done'));

        return redirect()->to('/login');
    }

    private function userId(string $email): ?int
    {
        $row = db_connect()->query(
            "SELECT user_id FROM auth_identities WHERE type = 'email_password' AND secret = :e:",
            ['e' => $email],
        )->getRow();

        return $row === null ? null : (int) $row->user_id;
    }

    private function forgotPage(string $email, bool $sent, int $status, ?string $error = null): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setBody(view('auth/forgot', ['title' => lang('PasswordReset.title'), 'email' => $email, 'sent' => $sent, 'error' => $error]));
    }

    private function resetPage(string $token, bool $invalid, ?string $error, int $status): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setBody(view('auth/reset', ['title' => lang('PasswordReset.newTitle'), 'token' => $token, 'invalid' => $invalid, 'error' => $error]));
    }
}
