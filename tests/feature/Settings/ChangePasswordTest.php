<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Auth\TrustedDeviceService;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\Mock\MockEmail;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Zmena hesla v Nastaveniach so znovuoverenim (AUTH-05, AUTH-04, D-17, D-19, D-20).
 *
 * @internal
 */
#[Group('feature')]
final class ChangePasswordTest extends AuthTestCase
{
    private const T0           = '2026-10-24T10:00:00+02:00';
    private const EMAIL        = 'owner@example.test';
    private const NEW_PASSWORD = 'Nove-Heslo-Slnko-2026!';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock(self::T0);
        Services::injectMock('clock', $this->clock);
        config('Email')->transport = 'smtp';
        $this->mockEmail();
    }

    protected function tearDown(): void
    {
        config('Email')->transport = 'smtp';
        Services::reset();
        parent::tearDown();
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    /**
     * @param array<string, string> $data
     */
    private function postPassword(array $data): TestResponse
    {
        $this->withSession();

        return $this->call('post', '/settings/security/password', $data + [csrf_token() => csrf_hash()]);
    }

    private function eventCount(string $event): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM auth_events WHERE event = ?', [$event])->getRow()->c;
    }

    private function mail(): MockEmail
    {
        $mail = service('email');
        $this->assertInstanceOf(MockEmail::class, $mail);

        return $mail;
    }

    private function loggedInOwner(): User
    {
        $user = $this->createUser(self::EMAIL);
        $this->enrollTotp($user);
        $this->loginAs($user);

        return $user;
    }

    public function testCardHasThreeFieldsAndHelp(): void
    {
        $this->loggedInOwner();
        $this->withSession();

        $html = $this->body($this->get('/settings/security'));

        $this->assertStringContainsString('name="current_password"', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('name="password_confirm"', $html);
        $this->assertSame(2, substr_count($html, 'autocomplete="new-password"'));
        $this->assertStringContainsString(esc(lang('Account.passwordHelp')), $html);
    }

    public function testWrongCurrentPasswordIsRejectedAndAudited(): void
    {
        $this->loggedInOwner();

        $result = $this->postPassword(['current_password' => 'zle-heslo-123456', 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Account.reauthFailed')), $this->body($result));
        $this->assertSame(1, $this->eventCount('login_password_fail'));
        $detail = (string) $this->owner()->query("SELECT detail FROM auth_events WHERE event = 'login_password_fail'")->getRow()->detail;
        $this->assertSame('reauth', $detail);
        $this->assertSame(0, $this->eventCount('password_changed'));
    }

    public function testMismatchAndWeakPasswordsAreRejected(): void
    {
        $user = $this->loggedInOwner();
        $hash = $this->owner()->query('SELECT secret2 FROM auth_identities WHERE user_id = ? AND type = ?', [$user->id, 'email_password'])->getRow()->secret2;

        $this->postPassword(['current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirm' => 'ina-hodnota-12345'])->assertStatus(422);
        $this->postPassword(['current_password' => self::PASSWORD, 'password' => 'kratke', 'password_confirm' => 'kratke'])->assertStatus(422);

        $after = $this->owner()->query('SELECT secret2 FROM auth_identities WHERE user_id = ? AND type = ?', [$user->id, 'email_password'])->getRow()->secret2;
        $this->assertSame($hash, $after);
        $this->assertSame(0, $this->eventCount('password_changed'));
    }

    public function testSuccessKeepsThisSessionAndEndsOthers(): void
    {
        $user        = $this->loggedInOwner();
        $deviceToken = $this->trustDevice($user);
        $epoch       = (new TrustedDeviceService())->currentEpoch((int) $user->id);
        $this->mockEmail();

        $result = $this->postPassword(['current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);

        $result->assertStatus(302);
        $this->assertStringEndsWith('/settings/security', $result->response()->getHeaderLine('Location'));
        $notice = session()->getFlashdata('app_notice');
        $this->assertIsArray($notice);
        $this->assertSame(lang('Account.passwordChanged'), $notice['message']);
        $this->assertSame($epoch + 1, (new TrustedDeviceService())->currentEpoch((int) $user->id));
        $this->assertSame($epoch + 1, session('auth_epoch'));
        $this->assertNull((new TrustedDeviceService())->findValid($deviceToken, $this->clock->now()));
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM trusted_devices WHERE user_id = ? AND revoked_at IS NULL', [$user->id])->getRow()->c);
        $cookie = $result->response()->getCookie('__Host-wt_device');
        $this->assertNotNull($cookie);
        $this->assertSame('', $cookie->getValue());
        $this->assertSame(1, $this->eventCount('password_changed'));
        $this->assertSame([self::EMAIL], $this->mail()->archive['recipients'] ?? null);

        // Tato relacia ostava prihlasena.
        $this->withSession();
        $this->get('/')->assertStatus(200);

        // Ina relacia (stara epocha) je odhlasena.
        $this->actingAs($user);
        $this->withSession(['auth_epoch' => $epoch, 'last_activity' => $this->clock->now()->getTimestamp()]);
        $other = $this->get('/');
        $other->assertStatus(302);
        $this->assertStringEndsWith('/login', $other->response()->getHeaderLine('Location'));
    }

    public function testNewPasswordLogsInToSecondFactor(): void
    {
        $this->loggedInOwner();
        $this->postPassword(['current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD])->assertStatus(302);
        auth('session')->logout();
        $this->withSession([]);

        $login = $this->call('post', '/login', ['email' => self::EMAIL, 'password' => self::NEW_PASSWORD, csrf_token() => csrf_hash()]);

        $login->assertStatus(302);
        $this->assertStringEndsWith('/2fa', $login->response()->getHeaderLine('Location'));
    }

    public function testRepeatedWrongPasswordsLockAccountAndNotify(): void
    {
        $this->loggedInOwner();
        $this->mockEmail();
        $last = null;

        for ($i = 0; $i < 5; $i++) {
            $last = $this->postPassword(['current_password' => 'zle-heslo-123456', 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        }

        $this->assertNotNull($last);
        $last->assertStatus(429);
        $this->assertSame(1, $this->eventCount('lockout'));
        $this->assertSame([self::EMAIL], $this->mail()->archive['recipients'] ?? null);

        // Aj spravne heslo je pocas zamku odmietnute.
        $this->postPassword(['current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD])->assertStatus(429);
        $this->assertSame(0, $this->eventCount('password_changed'));
    }
}
