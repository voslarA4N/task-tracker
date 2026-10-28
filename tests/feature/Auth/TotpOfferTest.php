<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Libraries\Auth\TotpService;
use App\Libraries\Auth\TrustedDeviceService;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * 2FA je volitelna (2026-10-28): ponuka pri prvom prihlaseni, odmietnutie sa pamata, prihlasenie iba heslom,
 * zapamatanie zariadenia aj bez 2FA.
 *
 * @internal
 */
#[Group('feature')]
final class TotpOfferTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-28T10:00:00+01:00'));
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function pending(bool $totp = false): User
    {
        $user = $this->createUser('owner@example.test');

        if ($totp) {
            $this->enrollTotp($user);
        }

        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());

        return $user;
    }

    /**
     * @param array<string, string> $headers
     */
    private function fetch(string $path, array $headers = []): TestResponse
    {
        $this->withSession();

        return $this->withHeaders($headers)->get($path);
    }

    /**
     * @param array<string, string> $data
     */
    private function postTo(string $path, array $data = [], bool $csrf = true): TestResponse
    {
        $this->withSession();

        return $this->call('post', $path, $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    private function location(TestResponse $r): string
    {
        return $r->response()->getHeaderLine('Location');
    }

    private function row(User $user): object
    {
        return $this->owner()->query('SELECT totp_declined_at, totp_enabled_at FROM user_security WHERE user_id = ?', [$user->id])->getRow();
    }

    private function eventCount(string $event): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM auth_events WHERE event = ?', [$event])->getRow()->c;
    }

    public function testUndecidedUserIsSentToOffer(): void
    {
        $this->pending();
        $r = $this->fetch('/2fa');
        $r->assertStatus(302);
        $this->assertStringEndsWith('/2fa/offer', $this->location($r));
    }

    public function testOfferPageShowsBothChoices(): void
    {
        $this->pending();
        $r = $this->fetch('/2fa/offer');
        $r->assertStatus(200);
        $body = (string) $r->response()->getBody();

        $this->assertStringContainsString('href="/2fa/setup"', $body);
        $this->assertStringContainsString('action="/2fa/offer/decline"', $body);
        $this->assertStringContainsString('name="' . csrf_token() . '"', $body);
    }

    public function testPendingUserCannotReachAppBeforeChoosing(): void
    {
        $this->pending();
        $r = $this->fetch('/');
        $r->assertStatus(302);
        $this->assertStringEndsWith('/2fa', $this->location($r));
        $this->assertFalse(auth('session')->loggedIn());

        $h = $this->fetch('/tasks', ['HX-Request' => 'true']);
        $this->assertStringEndsWith('/2fa', $h->response()->getHeaderLine('HX-Redirect') . $this->location($h));
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testDeclinePersistsAndCompletesLogin(): void
    {
        $user = $this->pending();
        $r    = $this->postTo('/2fa/offer/decline');

        $r->assertStatus(302);
        $this->assertStringEndsWith('/', $this->location($r));
        $this->assertNotNull($this->row($user)->totp_declined_at);
        $this->assertNull($this->row($user)->totp_enabled_at);
        $this->assertSame(1, $this->eventCount('totp_declined'));
        $this->assertSame(1, $this->eventCount('login_no_totp'));
        $this->assertTrue(auth('session')->loggedIn());
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM trusted_devices')->getRow()->c);
    }

    public function testDeclineWithRememberIssuesTrustedDevice(): void
    {
        $user = $this->pending();
        $r    = $this->postTo('/2fa/offer/decline', ['remember' => '1']);

        $r->assertStatus(302);
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM trusted_devices WHERE user_id = ? AND revoked_at IS NULL', [$user->id])->getRow()->c);
        $this->assertTrue($r->response()->hasCookie('wt_device', null, TrustedDeviceService::COOKIE_PREFIX));
    }

    public function testDeclineWithoutCsrfIsForbidden(): void
    {
        $user = $this->pending();

        try {
            $r = $this->postTo('/2fa/offer/decline', [], false);
            $this->assertSame(403, $r->response()->getStatusCode());
        } catch (SecurityException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($this->row($user)->totp_declined_at);
    }

    public function testDeclinedUserLogsInWithPasswordOnly(): void
    {
        $user = $this->createUser('owner@example.test');
        $this->owner()->query('UPDATE user_security SET totp_declined_at = now() WHERE user_id = ?', [$user->id]);
        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());

        $r = $this->fetch('/2fa');
        $this->assertStringEndsWith('/2fa/continue', $this->location($r));

        $page = $this->fetch('/2fa/continue');
        $page->assertStatus(200);
        $this->assertStringContainsString('action="/2fa/continue"', (string) $page->response()->getBody());

        // ponuka sa uz nikdy neukaze
        $offer = $this->fetch('/2fa/offer');
        $this->assertStringEndsWith('/2fa', $this->location($offer));

        $done = $this->postTo('/2fa/continue');
        $done->assertStatus(302);
        $this->assertStringEndsWith('/', $this->location($done));
        $this->assertTrue(auth('session')->loggedIn());
        $this->assertSame(1, $this->eventCount('login_no_totp'));
    }

    public function testDeclinedUserCanRememberDevice(): void
    {
        $user = $this->createUser('owner@example.test');
        $this->owner()->query('UPDATE user_security SET totp_declined_at = now() WHERE user_id = ?', [$user->id]);
        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());

        $this->postTo('/2fa/continue', ['remember' => '1'])->assertStatus(302);
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM trusted_devices WHERE user_id = ? AND revoked_at IS NULL', [$user->id])->getRow()->c);
    }

    public function testUserWithTotpNeverSeesOffer(): void
    {
        $user = $this->pending(true);

        $this->assertStringEndsWith('/2fa/verify', $this->location($this->fetch('/2fa')));
        $this->assertStringEndsWith('/2fa/verify', $this->location($this->fetch('/2fa/offer')));
        $this->assertStringEndsWith('/2fa/verify', $this->location($this->postTo('/2fa/offer/decline')));
        $this->assertStringEndsWith('/2fa', $this->location($this->fetch('/2fa/continue')));
        $this->assertNull($this->row($user)->totp_declined_at);
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testExpiredPendingOfferRedirectsToLogin(): void
    {
        $this->pending();
        session()->set('pending_since', service('clock')->now()->getTimestamp() - 1801);

        $this->assertStringEndsWith('/login', $this->location($this->fetch('/2fa/offer')));
    }

    public function testChoosingSetupFromOfferStillEnrols(): void
    {
        $user = $this->pending();

        $this->fetch('/2fa/setup')->assertStatus(200);
        $enc = session('totp_setup_secret');
        $this->assertIsString($enc);
        $secret = (new TotpService())->decryptSecret($enc);
        $code   = $this->totpCode($secret);

        $r = $this->postTo('/2fa/setup', ['code' => $code]);
        $r->assertStatus(302);
        $this->assertStringEndsWith('/2fa/backup-codes', $this->location($r));
        $this->assertNotNull($this->row($user)->totp_enabled_at);
        $this->assertNull($this->row($user)->totp_declined_at);
    }
}
