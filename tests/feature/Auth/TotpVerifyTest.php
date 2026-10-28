<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Authentication\Actions\TotpAction;
use App\Libraries\Auth\AccountRecovery;
use App\Libraries\Auth\TrustedDeviceService;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * @internal
 */
#[Group('feature')]
final class TotpVerifyTest extends AuthTestCase
{
    private const T0 = '2026-10-24T10:00:00+02:00';

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock(self::T0));
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function pending(bool $withTotp = true): User
    {
        $user = $this->createUser('owner@example.test');

        if ($withTotp) {
            $this->secret = $this->enrollTotp($user);
        }

        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());

        return $user;
    }

    private function fetch(string $path): TestResponse
    {
        $this->withSession();

        return $this->get($path);
    }

    private function postCode(string $code): TestResponse
    {
        $this->withSession();

        return $this->call('post', '/2fa/verify', ['code' => $code, csrf_token() => csrf_hash()]);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function eventCount(string $event, ?string $detail = null): int
    {
        if ($detail === null) {
            return (int) $this->owner()->query('SELECT count(*) AS c FROM auth_events WHERE event = ?', [$event])->getRow()->c;
        }

        return (int) $this->owner()->query('SELECT count(*) AS c FROM auth_events WHERE event = ? AND detail = ?', [$event, $detail])->getRow()->c;
    }

    private function wrong(): string
    {
        return $this->totpCode($this->secret) === '000000' ? '000001' : '000000';
    }

    private function relogin(User $user): void
    {
        $this->owner()->query('TRUNCATE auth_lockouts');
        auth('session')->logout();
        $_SESSION = [];
        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());
    }

    public function testLoginRedirectsThroughTwoFactorToVerify(): void
    {
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);

        $result = $this->call('post', '/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD, csrf_token() => csrf_hash()]);
        $result->assertStatus(302);
        $this->assertStringEndsWith('/2fa', $result->response()->getHeaderLine('Location'));

        $this->assertStringEndsWith('/2fa/verify', $this->fetch('/2fa')->response()->getHeaderLine('Location'));
    }

    public function testVerifyPageRenders(): void
    {
        $this->pending();
        $result = $this->fetch('/2fa/verify');
        $result->assertStatus(200);
        $body = $this->body($result);

        $this->assertStringContainsString('name="code"', $body);
        $this->assertStringContainsString('autocomplete="one-time-code"', $body);
        $this->assertStringContainsString('autofocus', $body);
        $this->assertStringContainsString(esc(lang('TwoFactor.verifySubmit')), $body);
        $this->assertStringContainsString('action="/logout"', $body);
    }

    public function testCorrectCodeLogsIn(): void
    {
        $this->pending();

        $result = $this->postCode($this->totpCode($this->secret));
        $result->assertStatus(302);
        $this->assertStringEndsWith('/', $result->response()->getHeaderLine('Location'));
        $this->assertTrue(auth('session')->loggedIn());
        $this->assertSame(1, $this->eventCount('login_totp_ok'));
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM auth_lockouts')->getRow()->c);
    }

    public function testSameCodeCannotBeReused(): void
    {
        $user = $this->pending();
        $code = $this->totpCode($this->secret);
        $this->postCode($code)->assertStatus(302);

        $this->relogin($user);
        $result = $this->postCode($code);
        $result->assertStatus(422);
        $this->assertFalse(auth('session')->loggedIn());
        $this->assertStringContainsString(esc(lang('TwoFactor.errorInvalidCode')), $this->body($result));
    }

    public function testNextStepCodeWorks(): void
    {
        $user = $this->pending();
        $this->postCode($this->totpCode($this->secret))->assertStatus(302);

        Services::injectMock('clock', new FrozenClock('2026-10-24T10:00:30+02:00'));
        $this->relogin($user);
        $this->postCode($this->totpCode($this->secret))->assertStatus(302);
        $this->assertTrue(auth('session')->loggedIn());
    }

    public function testOlderStepRejectedAfterNewerUsed(): void
    {
        $user    = $this->pending();
        $older   = $this->totpCode($this->secret, -30);
        $current = $this->totpCode($this->secret);
        $this->postCode($current)->assertStatus(302);

        $this->relogin($user);
        $this->postCode($older)->assertStatus(422);
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testWrongCodeIsAuditedAndRejected(): void
    {
        $this->pending();
        $this->postCode($this->wrong())->assertStatus(422);
        $this->assertFalse(auth('session')->loggedIn());
        $this->assertSame(1, $this->eventCount('login_totp_fail', 'totp'));
    }

    public function testMalformedCodeCountsAsFailure(): void
    {
        $this->pending();
        $this->postCode('abc')->assertStatus(422);
        $this->assertSame(1, $this->eventCount('login_totp_fail', 'totp'));
    }

    public function testPasswordAndCodeFailuresShareLockout(): void
    {
        $this->createUser('owner@example.test');

        for ($i = 0; $i < 3; $i++) {
            $this->call('post', '/login', ['email' => 'owner@example.test', 'password' => 'Zle-Heslo-123!', csrf_token() => csrf_hash()])->assertStatus(422);
        }

        $user = (new UserModel())->findByCredentials(['email' => 'owner@example.test']);
        $this->assertNotNull($user);
        $this->secret = $this->enrollTotp($user);
        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());

        $this->postCode($this->wrong())->assertStatus(422);
        $this->postCode($this->wrong())->assertStatus(429);
        $this->assertSame(1, $this->eventCount('lockout'));
    }

    public function testUserWithoutTotpGoesToGateway(): void
    {
        $this->pending(false);
        $result = $this->fetch('/2fa/verify');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/2fa', $result->response()->getHeaderLine('Location'));
    }

    public function testLoggedInUserGoesHome(): void
    {
        $user = $this->createUser('owner@example.test');
        $this->loginAs($user);
        $result = $this->fetch('/2fa/verify');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/', $result->response()->getHeaderLine('Location'));
    }

    public function testAnonymousGoesToLogin(): void
    {
        $result = $this->fetch('/2fa/verify');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testExpiredPendingRedirectsToLogin(): void
    {
        $this->pending();
        session()->set('pending_since', service('clock')->now()->getTimestamp() - 601);

        $result = $this->fetch('/2fa/verify');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        $this->assertSame(lang('TwoFactor.pendingExpired'), session()->getFlashdata('auth_info'));
    }

    public function testExpiredPendingPostRedirectsToLogin(): void
    {
        $this->pending();
        session()->set('pending_since', service('clock')->now()->getTimestamp() - 601);
        $result = $this->postCode($this->totpCode($this->secret));
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testPasswordChangeDuringPendingBlocksCompletion(): void
    {
        $user                      = $this->pending();
        config('Email')->transport = 'log';

        (new AccountRecovery())->setPassword($user, 'Nove-Heslo-Slnko-2026!', 'cli', service('clock')->now());
        $this->assertNull(model(UserIdentityModel::class)->getIdentityByType($user, TotpAction::TYPE));

        $result = $this->postCode($this->totpCode($this->secret));
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        $this->assertFalse(auth('session')->loggedIn());
        $this->assertSame(0, $this->eventCount('login_totp_ok'));
    }

    public function testEpochBumpDuringPendingBlocksCompletion(): void
    {
        $user = $this->pending();

        (new TrustedDeviceService())->bumpEpoch((int) $user->id, service('clock')->now());

        $result = $this->postCode($this->totpCode($this->secret));
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        $this->assertFalse(auth('session')->loggedIn());
        $authenticator = auth('session')->getAuthenticator();
        $this->assertInstanceOf(Session::class, $authenticator);
        $this->assertFalse($authenticator->isPending());
        $this->assertSame(0, $this->eventCount('login_totp_ok'));
    }

    public function testCompletedLoginCarriesEpochFromPasswordStep(): void
    {
        $user = $this->pending();

        $this->postCode($this->totpCode($this->secret))->assertStatus(302);

        $this->assertTrue(auth('session')->loggedIn());
        $this->assertSame((new TrustedDeviceService())->currentEpoch((int) $user->id), session('auth_epoch'));
        $this->assertNull(session('pending_epoch'));
    }

    public function testPendingUserSeesStoredLocaleNotAcceptLanguage(): void
    {
        $user = $this->pending();
        $this->owner()->query("UPDATE user_security SET locale = 'en' WHERE user_id = ?", [$user->id]);

        $this->withSession();
        $body = $this->body($this->withHeaders(['Accept-Language' => 'sk-SK,sk;q=0.9'])->get('/2fa/verify'));

        $this->assertStringContainsString('<html lang="en"', $body);
        $this->assertStringContainsString(esc(lang('TwoFactor.verifySubmit', [], 'en')), $body);
    }
}
