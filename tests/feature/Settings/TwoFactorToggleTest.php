<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Auth\BackupCodeService;
use App\Libraries\Auth\TotpService;
use App\Libraries\Auth\TrustedDeviceService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\Mock\MockEmail;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Zapnutie a vypnutie 2FA v Nastaveniach -> Bezpecnost (D-02, D-03).
 *
 * @internal
 */
#[Group('feature')]
final class TwoFactorToggleTest extends AuthTestCase
{
    private const EMAIL = 'owner@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config('Email')->transport = 'smtp';
        $this->mockEmail();
    }

    protected function tearDown(): void
    {
        config('Email')->transport = 'smtp';
        Services::reset();
        parent::tearDown();
    }

    private function body(TestResponse $r): string
    {
        return (string) $r->response()->getBody();
    }

    /**
     * @param array<string, string> $data
     */
    private function postTo(string $path, array $data = [], bool $csrf = true): TestResponse
    {
        $this->withSession();

        return $this->call('post', $path, $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    private function fetch(string $path): TestResponse
    {
        $this->withSession();

        return $this->get($path);
    }

    private function eventCount(string $event, ?string $detail = null): int
    {
        if ($detail === null) {
            return (int) $this->owner()->query('SELECT count(*) AS c FROM auth_events WHERE event = ?', [$event])->getRow()->c;
        }

        return (int) $this->owner()->query('SELECT count(*) AS c FROM auth_events WHERE event = ? AND detail = ?', [$event, $detail])->getRow()->c;
    }

    private function sec(User $user): object
    {
        return $this->owner()->query('SELECT totp_secret_enc, totp_enabled_at, totp_last_step, totp_declined_at FROM user_security WHERE user_id = ?', [$user->id])->getRow();
    }

    /**
     * @return array{0: User, 1: string, 2: list<string>}
     */
    private function enrolled(): array
    {
        $user   = $this->createUser(self::EMAIL);
        $secret = $this->enrollTotp($user);
        $codes  = (new BackupCodeService())->generate((int) $user->id, service('clock')->now());
        $this->loginAs($user);

        return [$user, $secret, $codes];
    }

    private function plain(): User
    {
        $user = $this->createUser(self::EMAIL);
        $this->loginAs($user);

        return $user;
    }

    public function testPageWithoutTotpShowsInactiveAndEnableLink(): void
    {
        $this->plain();
        $html = $this->body($this->fetch('/settings/security'));

        $this->assertStringContainsString(esc(lang('Account.twofaInactive')), $html);
        $this->assertStringContainsString('href="/settings/security/2fa/setup"', $html);
        $this->assertStringNotContainsString('action="/settings/security/2fa/disable"', $html);
        $this->assertStringNotContainsString('data-bs-target="#regenerate-codes"', $html);
    }

    public function testPageWithTotpShowsDisableModal(): void
    {
        $this->enrolled();
        $html = $this->body($this->fetch('/settings/security'));

        $this->assertStringContainsString('action="/settings/security/2fa/disable"', $html);
        $this->assertStringContainsString('name="current_password"', $html);
        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('data-bs-target="#regenerate-codes"', $html);
    }

    public function testEnableShowsQrAndActivatesWithPasswordAndCode(): void
    {
        $user = $this->plain();
        $this->owner()->query('UPDATE user_security SET totp_declined_at = now() WHERE user_id = ?', [$user->id]);

        $page = $this->fetch('/settings/security/2fa/setup');
        $page->assertStatus(200);
        $this->assertStringContainsString('<svg', $this->body($page));

        $secret = (new TotpService())->decryptSecret((string) session('settings_totp_secret'));
        $r      = $this->postTo('/settings/security/2fa/setup', ['current_password' => self::PASSWORD, 'code' => $this->totpCode($secret)]);

        $r->assertStatus(302);
        $this->assertStringEndsWith('/settings/security/backup-codes', $r->response()->getHeaderLine('Location'));
        $sec = $this->sec($user);
        $this->assertNotNull($sec->totp_enabled_at);
        $this->assertNull($sec->totp_declined_at);
        $this->assertSame(10, (new BackupCodeService())->remaining((int) $user->id));
        $this->assertSame(1, $this->eventCount('totp_enrolled', 'settings'));
        $this->assertSame([self::EMAIL], $this->mail()->archive['recipients'] ?? null);

        $this->fetch('/settings/security')->assertStatus(200);
    }

    public function testEnableWrongPasswordOrWrongCodeIs422(): void
    {
        $user = $this->plain();
        $this->fetch('/settings/security/2fa/setup');
        $secret = (new TotpService())->decryptSecret((string) session('settings_totp_secret'));

        $this->postTo('/settings/security/2fa/setup', ['current_password' => 'zle-heslo-123456', 'code' => $this->totpCode($secret)])->assertStatus(422);
        $this->postTo('/settings/security/2fa/setup', ['current_password' => self::PASSWORD, 'code' => '000000'])->assertStatus(422);

        $this->assertNull($this->sec($user)->totp_enabled_at);
        $this->assertSame(1, $this->eventCount('login_totp_fail', 'enable'));
        $this->assertSame(1, $this->eventCount('login_password_fail', 'reauth'));
    }

    public function testEnableWhenAlreadyActiveRedirects(): void
    {
        [$user] = $this->enrolled();
        $before = $this->sec($user)->totp_secret_enc;

        $this->fetch('/settings/security/2fa/setup')->assertStatus(302);
        $this->postTo('/settings/security/2fa/setup', ['current_password' => self::PASSWORD, 'code' => '123456'])->assertStatus(302);
        $this->assertSame($before, $this->sec($user)->totp_secret_enc);
    }

    public function testDisableWithPasswordAndTotp(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->trustDevice($user);
        $mail = $this->mail();

        $r = $this->postTo('/settings/security/2fa/disable', ['current_password' => self::PASSWORD, 'code' => $this->totpCode($secret)]);

        $r->assertStatus(302);
        $this->assertStringEndsWith('/settings/security', $r->response()->getHeaderLine('Location'));
        $sec = $this->sec($user);
        $this->assertNull($sec->totp_secret_enc);
        $this->assertNull($sec->totp_enabled_at);
        $this->assertNull($sec->totp_last_step);
        $this->assertNotNull($sec->totp_declined_at);
        $this->assertSame(0, (new BackupCodeService())->remaining((int) $user->id));
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM trusted_devices WHERE user_id = ? AND revoked_at IS NULL', [$user->id])->getRow()->c);
        $this->assertTrue($r->response()->hasCookie(TrustedDeviceService::COOKIE_NAME, null, TrustedDeviceService::COOKIE_PREFIX));
        $this->assertSame(1, $this->eventCount('totp_disabled'));
        $this->assertSame([self::EMAIL], $mail->archive['recipients'] ?? null);

        $page = $this->fetch('/settings/security');
        $page->assertStatus(200);
        $this->assertStringContainsString(esc(lang('Account.twofaInactive')), $this->body($page));
    }

    public function testDisabledUserLogsInWithoutOffer(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->postTo('/settings/security/2fa/disable', ['current_password' => self::PASSWORD, 'code' => $this->totpCode($secret)])->assertStatus(302);

        auth('session')->logout();
        $_SESSION = [];
        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());

        $r = $this->fetch('/2fa');
        $this->assertStringEndsWith('/2fa/continue', $r->response()->getHeaderLine('Location'));
    }

    public function testDisableWithBackupCode(): void
    {
        [$user, , $codes] = $this->enrolled();

        $r = $this->postTo('/settings/security/2fa/disable', ['current_password' => self::PASSWORD, 'code' => $codes[0]]);

        $r->assertStatus(302);
        $this->assertNull($this->sec($user)->totp_enabled_at);
        $this->assertSame(1, $this->eventCount('totp_disabled'));
    }

    public function testDisableRejectsWrongPassword(): void
    {
        [$user, $secret] = $this->enrolled();

        $this->postTo('/settings/security/2fa/disable', ['current_password' => 'zle-heslo-123456', 'code' => $this->totpCode($secret)])->assertStatus(422);

        $this->assertNotNull($this->sec($user)->totp_enabled_at);
        $this->assertSame(1, $this->eventCount('login_password_fail', 'reauth'));
    }

    public function testDisableRejectsWrongAndReplayedCode(): void
    {
        [$user, $secret] = $this->enrolled();

        $this->postTo('/settings/security/2fa/disable', ['current_password' => self::PASSWORD, 'code' => '000000'])->assertStatus(422);

        // kod s krokom rovnym poslednemu pouzitemu sa odmietne
        $code = $this->totpCode($secret);
        $step = (new TotpService())->matchStep($secret, $code, null);
        $this->assertNotNull($step);
        $this->owner()->query('UPDATE user_security SET totp_last_step = ? WHERE user_id = ?', [$step, $user->id]);
        $this->postTo('/settings/security/2fa/disable', ['current_password' => self::PASSWORD, 'code' => $code])->assertStatus(422);

        $this->assertNotNull($this->sec($user)->totp_enabled_at);
        $this->assertSame(2, $this->eventCount('login_totp_fail', 'disable'));

        // dalsie zlyhania vedu k zamku
        $last = 0;

        for ($i = 0; $i < 6; $i++) {
            $last = $this->postTo('/settings/security/2fa/disable', ['current_password' => self::PASSWORD, 'code' => '000000'])->response()->getStatusCode();

            if ($last === 429) {
                break;
            }
        }

        $this->assertSame(429, $last);
        $this->assertNotNull($this->sec($user)->totp_enabled_at);
    }

    public function testDisableWithoutCsrfIsForbidden(): void
    {
        [$user, $secret] = $this->enrolled();

        try {
            $status = $this->postTo('/settings/security/2fa/disable', ['current_password' => self::PASSWORD, 'code' => $this->totpCode($secret)], false)->response()->getStatusCode();
            $this->assertSame(403, $status);
        } catch (\CodeIgniter\Security\Exceptions\SecurityException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNotNull($this->sec($user)->totp_enabled_at);
    }

    public function testRegenerateCodesWithoutTotpIsRefused(): void
    {
        $user = $this->plain();

        $r = $this->postTo('/settings/security/backup-codes', ['current_password' => self::PASSWORD]);

        $r->assertStatus(302);
        $this->assertStringEndsWith('/settings/security', $r->response()->getHeaderLine('Location'));
        $this->assertSame(0, (new BackupCodeService())->remaining((int) $user->id));
    }

    private function mail(): MockEmail
    {
        $mail = service('email');
        $this->assertInstanceOf(MockEmail::class, $mail);

        return $mail;
    }
}
