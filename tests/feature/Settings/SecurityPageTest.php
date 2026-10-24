<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Auth\BackupCodeService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Nastavenia, Zabezpecenie a stranka Viac (AUTH-03, AUTH-05, AUTH-07, D-20).
 *
 * @internal
 */
#[Group('feature')]
final class SecurityPageTest extends AuthTestCase
{
    private const SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1';
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    private function fetch(string $path): TestResponse
    {
        $this->withSession();

        return $this->get($path);
    }

    private function postTo(string $path, bool $csrf = true): TestResponse
    {
        $this->withSession();

        return $this->call('post', $path, $csrf ? [csrf_token() => csrf_hash()] : []);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    /**
     * @return array<string, mixed>
     */
    private function deviceRow(string $token): array
    {
        $row = $this->owner()->query('SELECT * FROM trusted_devices WHERE token_hash = ?', [hash('sha256', $token)])->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }

    private function insertEvent(User $user, string $event, bool $success, int $minute, string $ip = '198.51.100.9'): void
    {
        $this->owner()->query(
            'INSERT INTO auth_events (occurred_at, user_id, event, success, ip, device_label) VALUES (?, ?, ?, ?, ?, ?)',
            [sprintf('2026-10-24 %02d:%02d:00+02', intdiv($minute, 60), $minute % 60), $user->id, $event, $success ? 'true' : 'false', $ip, 'iPhone Safari'],
        );
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        foreach (['/settings/security', '/more'] as $path) {
            $result = $this->get($path);
            $result->assertStatus(302);
            $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        }
    }

    public function testNavLinksToMoreAndMorePageOffersSecurityAndLogout(): void
    {
        $this->loginAs($this->createUser());

        $home = $this->body($this->fetch('/'));
        $this->assertStringContainsString('href="/more"', $home);
        $this->assertStringNotContainsString('aria-disabled="true">' . esc(lang('App.navMore')), $home);

        $more = $this->fetch('/more');
        $more->assertStatus(200);
        $html = $this->body($more);
        $this->assertStringContainsString('href="/settings/security"', $html);
        $this->assertStringContainsString(esc(lang('Account.securityTitle')), $html);
        $this->assertMatchesRegularExpression('#<form[^>]+action="[^"]*/logout"#', $html);
    }

    public function testSecurityPageShowsTwoFactorStateAndRemainingCodes(): void
    {
        $user = $this->createUser();
        $this->enrollTotp($user);
        $codes = (new BackupCodeService())->generate((int) $user->id, service('clock')->now());
        $this->assertTrue((new BackupCodeService())->consume((int) $user->id, $codes[0], service('clock')->now()));
        $this->loginAs($user);

        $result = $this->fetch('/settings/security');
        $result->assertStatus(200);
        $html = $this->body($result);
        $this->assertStringContainsString(esc(lang('Account.twofaActive')), $html);
        $this->assertStringContainsString(esc(lang('Account.codesRemaining', [9])), $html);
        $this->assertStringContainsString('9 z 10', $html);
    }

    public function testDevicesAreListedWithBadgeDatesAndRevokeModal(): void
    {
        $user    = $this->createUser();
        $current = $this->trustDevice($user, self::SAFARI);
        $other   = $this->trustDevice($user, self::CHROME);
        $curRow  = $this->deviceRow($current);
        $othRow  = $this->deviceRow($other);
        $this->loginAs($user);
        session()->set('device_id', (int) $curRow['id']);

        $result = $this->fetch('/settings/security');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertSame(1, substr_count($html, esc(lang('Account.thisDevice'))));
        $this->assertStringContainsString('id="revoke-' . $curRow['id'] . '"', $html);
        $this->assertStringContainsString('id="revoke-' . $othRow['id'] . '"', $html);
        $this->assertStringContainsString('data-bs-toggle="modal"', $html);
        $this->assertStringContainsString('action="/settings/security/devices/' . $othRow['id'] . '/revoke"', $html);
        $this->assertStringContainsString('action="/settings/security/devices/' . $curRow['id'] . '/revoke"', $html);
        $this->assertSame(1, substr_count($html, esc(lang('Account.revokeCurrentWarning'))));
        $this->assertSame(2, substr_count($html, csrf_token()));
        $this->assertStringContainsString('2026', $html);
        $this->assertStringNotContainsString($current, $html);
        $this->assertStringNotContainsString($other, $html);
    }

    public function testEmptyStates(): void
    {
        $this->loginAs($this->createUser());

        $html = $this->body($this->fetch('/settings/security'));
        $this->assertStringContainsString(esc(lang('Account.devicesEmptyHeading')), $html);
        $this->assertStringContainsString(esc(lang('Account.devicesEmptyBody')), $html);
        $this->assertStringContainsString(esc(lang('Account.eventsEmptyHeading')), $html);
        $this->assertSame(0, substr_count($html, 'data-event-row'));
    }

    public function testEventsShowLatestFiftyNewestFirstWithStatusBadges(): void
    {
        $user = $this->createUser();

        for ($i = 0; $i < 60; $i++) {
            $this->insertEvent($user, 'login_password_ok', true, $i + 1, '198.51.100.' . (10 + $i));
        }
        $this->insertEvent($user, 'login_password_fail', false, 100, '203.0.113.50');
        $this->insertEvent($user, 'lockout', false, 101, '203.0.113.51');
        $this->loginAs($user);

        $html = $this->body($this->fetch('/settings/security'));

        $this->assertSame(50, substr_count($html, 'data-event-row'));
        $this->assertStringContainsString(esc(lang('Account.event_login_password_fail')), $html);
        $this->assertStringContainsString(esc(lang('Account.statusFailed')), $html);
        $this->assertStringContainsString(esc(lang('Account.statusLocked')), $html);
        $this->assertStringContainsString(esc(lang('Account.statusOk')), $html);
        $this->assertStringContainsString('203.0.113.51', $html);
        $this->assertLessThan(strpos($html, '203.0.113.50'), strpos($html, '203.0.113.51'));
        // najstarsie udalosti (11 az 60 z 60 je v limite, 1. az 10. nie)
        $this->assertStringNotContainsString('198.51.100.10<', $html);
        $this->assertStringContainsString('198.51.100.69', $html);
    }

    public function testRevokingOtherDeviceKeepsSessionAndAudits(): void
    {
        $user    = $this->createUser();
        $current = $this->trustDevice($user, self::SAFARI);
        $other   = $this->trustDevice($user, self::CHROME);
        $curRow  = $this->deviceRow($current);
        $othRow  = $this->deviceRow($other);
        $this->loginAs($user);
        session()->set('device_id', (int) $curRow['id']);

        $result = $this->postTo('/settings/security/devices/' . $othRow['id'] . '/revoke');

        $result->assertStatus(302);
        $this->assertStringEndsWith('/settings/security', $result->response()->getHeaderLine('Location'));
        $this->assertNotNull($this->deviceRow($other)['revoked_at']);
        $this->assertNull($this->deviceRow($current)['revoked_at']);
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM auth_events WHERE event = 'device_revoked'")->getRow()->c);
        $this->assertNotNull(session()->getFlashdata('app_notice'));
    }

    public function testRevokingCurrentDeviceLogsOutAndClearsCookie(): void
    {
        $user    = $this->createUser();
        $current = $this->trustDevice($user, self::SAFARI);
        $curRow  = $this->deviceRow($current);
        $this->loginAs($user);
        session()->set('device_id', (int) $curRow['id']);

        $result = $this->postTo('/settings/security/devices/' . $curRow['id'] . '/revoke');

        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        $this->assertNotNull($this->deviceRow($current)['revoked_at']);
        $cookie = $result->response()->getCookie('__Host-wt_device');
        $this->assertNotNull($cookie);
        $this->assertSame('', $cookie->getValue());
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testCannotRevokeAnotherUsersDevice(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test', 'accountant');
        $token = $this->trustDevice($other, self::CHROME);
        $row   = $this->deviceRow($token);
        $this->loginAs($owner);

        $this->postTo('/settings/security/devices/' . $row['id'] . '/revoke')->assertStatus(404);
        $this->assertNull($this->deviceRow($token)['revoked_at']);
    }

    public function testRevokeWithoutCsrfTokenIsForbidden(): void
    {
        $user  = $this->createUser();
        $token = $this->trustDevice($user, self::CHROME);
        $row   = $this->deviceRow($token);
        $this->loginAs($user);

        $this->postTo('/settings/security/devices/' . $row['id'] . '/revoke', false)->assertStatus(403);
        $this->assertNull($this->deviceRow($token)['revoked_at']);
    }
}
