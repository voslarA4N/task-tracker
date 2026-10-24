<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Libraries\Auth\BackupCodeService;
use App\Libraries\Auth\TotpService;
use App\Libraries\Auth\TrustedDeviceService;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Cookie\Cookie;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\Mock\MockEmail;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Dovernost zariadenia (AUTH-05, PWA-01): rozhodnutia O-1 = revoke a O-5 = yes z 02-01-SUMMARY.
 *
 * @internal
 */
#[Group('feature')]
final class TrustedDeviceTest extends AuthTestCase
{
    private const T0     = '2026-10-24T10:00:00+02:00';
    private const COOKIE = '__Host-wt_device';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setClock(self::T0);
        config('Email')->transport = 'smtp';
        $this->mockEmail();
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function setClock(string $instant): void
    {
        $this->clock = new FrozenClock($instant);
        Services::injectMock('clock', $this->clock);
    }

    private function advance(int $seconds): void
    {
        $this->setClock($this->clock->now()->modify('+' . $seconds . ' seconds')->format(DATE_ATOM));
    }

    private function fetch(string $path): TestResponse
    {
        $this->withSession();

        return $this->get($path);
    }

    /**
     * @param array<string, string> $data
     */
    private function postTo(string $path, array $data): TestResponse
    {
        $this->withSession();

        return $this->call('post', $path, $data + [csrf_token() => csrf_hash()]);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function eventCount(string $event): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM auth_events WHERE event = ?', [$event])->getRow()->c;
    }

    private function deviceCookie(TestResponse $result): ?Cookie
    {
        foreach ($result->response()->getCookieStore() as $cookie) {
            if ($cookie->getPrefixedName() === self::COOKIE) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function deviceRow(?string $token = null): ?array
    {
        $sql = 'SELECT * FROM trusted_devices ORDER BY id DESC LIMIT 1';

        if ($token !== null) {
            return $this->owner()->query('SELECT * FROM trusted_devices WHERE token_hash = ? OR prev_token_hash = ?', [hash('sha256', $token), hash('sha256', $token)])->getRowArray();
        }

        return $this->owner()->query($sql)->getRowArray();
    }

    private function deviceCount(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM trusted_devices')->getRow()->c;
    }

    /**
     * Cakajuci pouzivatel s TOTP, pripraveny na /2fa/verify.
     *
     * @return array{0: User, 1: string}
     */
    private function pending(): array
    {
        $user   = $this->createUser('owner@example.test');
        $secret = $this->enrollTotp($user);
        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());

        return [$user, $secret];
    }

    private function freshBrowser(): void
    {
        auth('session')->logout();
        $_SESSION = [];
        $this->withSession([]);
    }

    public function testRememberIssuesHostCookieWithStrictAttributes(): void
    {
        [, $secret] = $this->pending();

        $result = $this->postTo('/2fa/verify', ['code' => $this->totpCode($secret), 'remember' => '1']);
        $result->assertStatus(302);

        $cookie = $this->deviceCookie($result);
        $this->assertNotNull($cookie, 'expected __Host-wt_device cookie');
        $this->assertSame(self::COOKIE, $cookie->getPrefixedName());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHTTPOnly());
        $this->assertSame('lax', strtolower($cookie->getSameSite()));
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('', $cookie->getDomain());
        // Max-Age pocita CI4 Cookie z realneho casu, preto sa overuje expiracia (T0 + 30 dni).
        $this->assertSame($this->clock->now()->getTimestamp() + 2592000, $cookie->getExpiresTimestamp());
        $this->assertStringContainsString('Max-Age=', (string) $cookie);

        $header = (string) $cookie;
        $this->assertStringContainsString('Secure', $header);
        $this->assertStringContainsString('HttpOnly', $header);
        $this->assertStringNotContainsString('Domain=', $header);

        $row = $this->deviceRow();
        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', $cookie->getValue()), $row['token_hash']);
        $this->assertNotSame($cookie->getValue(), $row['token_hash']);
        $this->assertSame(
            $this->clock->now()->getTimestamp() + 2592000,
            (int) $this->owner()->query('SELECT EXTRACT(EPOCH FROM expires_at)::bigint AS e FROM trusted_devices ORDER BY id DESC LIMIT 1')->getRow()->e,
        );
        $this->assertSame(1, $this->eventCount('device_trusted'));

        $mail = service('email');
        $this->assertInstanceOf(MockEmail::class, $mail);
        $this->assertNotEmpty($mail->archive);
        $this->assertSame(['owner@example.test'], $mail->archive['recipients'] ?? null);
    }

    public function testWithoutRememberNoDeviceIsIssued(): void
    {
        [, $secret] = $this->pending();

        $result = $this->postTo('/2fa/verify', ['code' => $this->totpCode($secret)]);
        $result->assertStatus(302);

        $this->assertNull($this->deviceCookie($result));
        $this->assertSame(0, $this->deviceCount());
        $this->assertSame(0, $this->eventCount('device_trusted'));
    }

    public function testVerifyAndBackupPagesHaveCheckedRememberCheckbox(): void
    {
        $this->pending();

        foreach (['/2fa/verify', '/2fa/backup'] as $path) {
            $body = $this->body($this->fetch($path));
            $this->assertMatchesRegularExpression('/<input[^>]*name="remember"[^>]*\bchecked\b/', $body, $path);
            $this->assertStringContainsString(esc(lang('TwoFactor.remember')), $body, $path);
        }
    }

    public function testBackupCodeLoginCanRememberDevice(): void
    {
        [$user] = $this->pending();
        $codes  = (new BackupCodeService())->generate((int) $user->id, $this->clock->now());

        $result = $this->postTo('/2fa/backup', ['code' => $codes[0], 'remember' => '1']);
        $result->assertStatus(302);

        $this->assertNotNull($this->deviceCookie($result));
        $this->assertSame(1, $this->deviceCount());
    }

    public function testFirstSetupConfirmStepCanRememberDevice(): void
    {
        $user = $this->createUser('owner@example.test');
        $this->startPending($user);
        session()->set('pending_since', $this->clock->now()->getTimestamp());
        $this->fetch('/2fa/setup');
        $secret = (new TotpService())->decryptSecret((string) session('totp_setup_secret'));
        $this->postTo('/2fa/setup', ['code' => $this->totpCode($secret)])->assertStatus(302);

        $page = $this->fetch('/2fa/backup-codes');
        $this->assertMatchesRegularExpression('/<input[^>]*name="remember"[^>]*\bchecked\b/', $this->body($page));

        $result = $this->postTo('/2fa/backup-codes', ['acknowledged' => '1', 'remember' => '1']);
        $result->assertStatus(302);
        $this->assertNotNull($this->deviceCookie($result));
        $this->assertSame(1, $this->deviceCount());
    }

    public function testNewSessionWithValidDeviceCookieLogsInAndRotates(): void
    {
        $user   = $this->createUser();
        $token  = $this->trustDevice($user);
        $before = $this->deviceRow($token);
        $this->assertNotNull($before);

        $this->freshBrowser();
        $this->withDeviceCookie($token);
        $result = $this->get('/');

        $result->assertStatus(200);
        $this->assertSame(1, $this->eventCount('device_login'));

        $cookie = $this->deviceCookie($result);
        $this->assertNotNull($cookie);
        $this->assertNotSame($token, $cookie->getValue());

        $after = $this->deviceRow($cookie->getValue());
        $this->assertNotNull($after);
        $this->assertSame($before['id'], $after['id']);
        $this->assertSame($before['expires_at'], $after['expires_at']);
    }

    public function testOldTokenWorksWithinGraceButNotAfter(): void
    {
        $user  = $this->createUser();
        $token = $this->trustDevice($user);

        $this->freshBrowser();
        $this->withDeviceCookie($token);
        $this->get('/')->assertStatus(200);

        $this->advance(60);
        $this->freshBrowser();
        $this->withDeviceCookie($token);
        $this->get('/')->assertStatus(200);

        $this->advance(2);
        $this->freshBrowser();
        $this->withDeviceCookie($token);
        $result = $this->get('/');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testExpiryIsFixedAndNotExtendedByUse(): void
    {
        $user  = $this->createUser();
        $token = $this->trustDevice($user);
        $first = $this->deviceRow($token);
        $this->assertNotNull($first);

        $this->setClock('2026-11-03T10:00:00+01:00');
        $this->freshBrowser();
        $this->withDeviceCookie($token);
        $result = $this->get('/');
        $result->assertStatus(200);

        $current = $this->deviceRow();
        $this->assertSame($first['expires_at'], $current['expires_at']);

        $cookie = $this->deviceCookie($result);
        $this->assertNotNull($cookie);

        $this->setClock('2026-11-23T09:00:01+01:00');
        $this->freshBrowser();
        $this->withDeviceCookie($cookie->getValue());
        $expired = $this->get('/');
        $expired->assertStatus(302);
        $this->assertStringEndsWith('/login', $expired->response()->getHeaderLine('Location'));
    }

    public function testRevokedForgedAndInactiveUserCookiesAreRejectedAndCleared(): void
    {
        $user    = $this->createUser();
        $revoked = $this->trustDevice($user);
        (new TrustedDeviceService())->revokeAll((int) $user->id, 'test', $this->clock->now());

        $cases = [
            'revoked' => $revoked,
            'forged'  => bin2hex(random_bytes(32)),
        ];

        foreach ($cases as $label => $token) {
            $this->freshBrowser();
            $this->withDeviceCookie($token);
            $result = $this->get('/');
            $result->assertStatus(302);
            $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'), $label);
            $cookie = $this->deviceCookie($result);
            $this->assertNotNull($cookie, $label);
            $this->assertSame('', $cookie->getValue(), $label);
        }

        $other = $this->createUser('second@example.test');
        $token = $this->trustDevice($other);
        $this->owner()->query('UPDATE users SET active = 0 WHERE id = ?', [$other->id]);
        $this->freshBrowser();
        $this->withDeviceCookie($token);
        $inactive = $this->get('/');
        $inactive->assertStatus(302);
        $this->assertStringEndsWith('/login', $inactive->response()->getHeaderLine('Location'));
        $this->assertSame('', $this->deviceCookie($inactive)?->getValue());
    }

    public function testRevokingDeviceEndsTrustedSessionOnNextRequest(): void
    {
        $user  = $this->createUser();
        $token = $this->trustDevice($user);
        $row   = $this->deviceRow($token);
        $this->assertNotNull($row);
        $this->loginAs($user);
        session()->set('device_id', (int) $row['id']);

        $this->fetch('/')->assertStatus(200);

        $this->assertTrue((new TrustedDeviceService())->revoke((int) $row['id'], (int) $user->id, 'manual', $this->clock->now()));

        $result = $this->fetch('/');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testBumpEpochEndsTrustedAndUntrustedSessions(): void
    {
        $user  = $this->createUser();
        $token = $this->trustDevice($user);
        $row   = $this->deviceRow($token);
        $this->assertNotNull($row);
        $svc = new TrustedDeviceService();

        $this->loginAs($user);
        session()->set('device_id', (int) $row['id']);
        $this->fetch('/')->assertStatus(200);
        $svc->bumpEpoch((int) $user->id, $this->clock->now());
        $this->fetch('/')->assertStatus(302);

        $this->freshBrowser();
        $this->loginAs($user);
        $this->fetch('/')->assertStatus(200);
        $svc->bumpEpoch((int) $user->id, $this->clock->now());
        $result = $this->fetch('/');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testBumpEpochAlsoInvalidatesDeviceCookie(): void
    {
        $user  = $this->createUser();
        $token = $this->trustDevice($user);
        (new TrustedDeviceService())->revokeAll((int) $user->id, 'password_changed', $this->clock->now());
        (new TrustedDeviceService())->bumpEpoch((int) $user->id, $this->clock->now());

        $this->freshBrowser();
        $this->withDeviceCookie($token);
        $this->get('/')->assertStatus(302);
    }

    public function testShieldRememberMeNeverLogsIn(): void
    {
        $user = $this->createUser();
        $this->owner()->query(
            'INSERT INTO auth_remember_tokens (selector, "hashedValidator", user_id, expires, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['selector1', hash('sha256', 'validator1'), $user->id, '2027-01-01 00:00:00+00', '2026-10-24 08:00:00+00', '2026-10-24 08:00:00+00'],
        );
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM auth_remember_tokens')->getRow()->c);

        $this->freshBrowser();
        service('superglobals')->setCookie('remember', 'selector1:validator1');
        $result = $this->get('/');

        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testLogoutRevokesCurrentDeviceAndDeletesCookie(): void
    {
        $user  = $this->createUser();
        $token = $this->trustDevice($user);
        $row   = $this->deviceRow($token);
        $this->assertNotNull($row);
        $this->loginAs($user);
        session()->set('device_id', (int) $row['id']);

        $result = $this->postTo('/logout', []);
        $result->assertStatus(302);

        $after = $this->deviceRow($token);
        $this->assertNotNull($after);
        $this->assertNotNull($after['revoked_at']);
        $this->assertSame('logout', $after['revoked_reason']);
        $this->assertSame('', $this->deviceCookie($result)?->getValue());
        $this->assertSame(1, $this->eventCount('device_revoked'));
    }

    public function testLogoutFromUntrustedSessionLeavesOtherDevices(): void
    {
        $user  = $this->createUser();
        $token = $this->trustDevice($user);
        $this->loginAs($user);

        $result = $this->postTo('/logout', []);
        $result->assertStatus(302);

        $row = $this->deviceRow($token);
        $this->assertNotNull($row);
        $this->assertNull($row['revoked_at']);
        $this->assertNull($this->deviceCookie($result));
    }
}
