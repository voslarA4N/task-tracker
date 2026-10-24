<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Libraries\Auth\TotpService;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * @internal
 */
#[Group('feature')]
final class TotpSetupTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-24T10:00:00+02:00'));
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function pendingUser(): User
    {
        $user = $this->createUser('owner@example.test');
        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());

        return $user;
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function sessionSecret(): string
    {
        $enc = session('totp_setup_secret');
        $this->assertIsString($enc);

        return (new TotpService())->decryptSecret($enc);
    }

    /**
     * Feature test zacina prazdnou session, preto sa aktualny stav $_SESSION prenasa explicitne.
     */
    private function fetch(string $path): TestResponse
    {
        $this->withSession();

        return $this->get($path);
    }

    private function postCode(string $code): TestResponse
    {
        $this->withSession();

        return $this->call('post', '/2fa/setup', ['code' => $code, csrf_token() => csrf_hash()]);
    }

    private function eventCount(string $event): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM auth_events WHERE event = ?', [$event])->getRow()->c;
    }

    public function testIndexRedirectsToSetup(): void
    {
        $this->pendingUser();
        $result = $this->fetch('/2fa');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/2fa/setup', $result->response()->getHeaderLine('Location'));
    }

    public function testSetupPageRenders(): void
    {
        $this->pendingUser();
        $result = $this->fetch('/2fa/setup');
        $result->assertStatus(200);
        $body = $this->body($result);

        $this->assertStringContainsString('<svg', $body);
        $this->assertStringContainsString('role="img"', $body);
        $this->assertStringContainsString('aria-label="' . esc(lang('TwoFactor.qrLabel'), 'attr') . '"', $body);
        $this->assertStringContainsString((new TotpService())->formatSecretForDisplay($this->sessionSecret()), $body);
        $this->assertStringContainsString('autocomplete="one-time-code"', $body);
        $this->assertStringContainsString('inputmode="numeric"', $body);
    }

    public function testAnonymousSetupRedirectsToLogin(): void
    {
        $result = $this->fetch('/2fa/setup');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testCorrectCodeActivatesAndLogsIn(): void
    {
        $user = $this->pendingUser();
        $this->fetch('/2fa/setup');
        $secret = $this->sessionSecret();

        $result = $this->postCode($this->totpCode($secret));
        $result->assertStatus(302);
        $this->assertStringEndsWith('/', rtrim($result->response()->getHeaderLine('Location'), ''));
        $this->assertTrue(auth('session')->loggedIn());

        $row = $this->owner()->query('SELECT totp_secret_enc, totp_enabled_at, totp_last_step FROM user_security WHERE user_id = ?', [$user->id])->getRow();
        $this->assertNotNull($row->totp_enabled_at);
        $this->assertNotNull($row->totp_last_step);
        $this->assertStringNotContainsString($secret, (string) $row->totp_secret_enc);
        $this->assertSame($secret, (new TotpService())->decryptSecret((string) $row->totp_secret_enc));
        $this->assertSame(1, $this->eventCount('totp_enrolled'));
        $this->assertSame(1, $this->eventCount('login_totp_ok'));
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM auth_lockouts')->getRow()->c);
    }

    public function testWrongCodeGives422AndStaysPending(): void
    {
        $this->pendingUser();
        $this->fetch('/2fa/setup');
        $secret = $this->sessionSecret();
        $good   = $this->totpCode($secret);
        $wrong  = $good === '000000' ? '000001' : '000000';

        $result = $this->postCode($wrong);
        $result->assertStatus(422);
        $this->assertFalse(auth('session')->loggedIn());
        $this->assertSame(1, $this->eventCount('login_totp_fail'));
        $this->assertStringContainsString(esc(lang('TwoFactor.errorInvalidCode')), $this->body($result));
    }

    public function testFiveWrongCodesLock(): void
    {
        $this->pendingUser();
        $this->fetch('/2fa/setup');
        $good  = $this->totpCode($this->sessionSecret());
        $wrong = $good === '000000' ? '000001' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->postCode($wrong)->assertStatus(422);
        }
        $this->postCode($wrong)->assertStatus(429);
    }

    public function testPendingOlderThanLoginTtlStillWorksInSetup(): void
    {
        $this->pendingUser();
        session()->set('pending_since', service('clock')->now()->getTimestamp() - 700);

        $this->fetch('/2fa/setup')->assertStatus(200);
        $secret = $this->sessionSecret();
        $this->postCode($this->totpCode($secret))->assertStatus(302);
        $this->assertTrue(auth('session')->loggedIn());
    }

    public function testPendingOlderThanEnrolTtlExpires(): void
    {
        $this->pendingUser();
        session()->set('pending_since', service('clock')->now()->getTimestamp() - 1801);

        $result = $this->fetch('/2fa/setup');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        $this->assertSame(lang('TwoFactor.pendingExpired'), session()->getFlashdata('auth_info'));
        $authenticator = auth('session')->getAuthenticator();
        $this->assertTrue(method_exists($authenticator, 'isPending') && ! $authenticator->isPending());
    }

    public function testUserWithTotpIsSentToVerify(): void
    {
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());

        $result = $this->fetch('/2fa/setup');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/2fa/verify', $result->response()->getHeaderLine('Location'));
    }
}
