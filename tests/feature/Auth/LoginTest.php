<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Controllers\Auth\Login;
use CodeIgniter\Test\Mock\MockEmail;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * @internal
 */
#[Group('feature')]
final class LoginTest extends AuthTestCase
{
    /**
     * @param array<string, string> $data
     */
    private function postLogin(array $data, string $path = '/login'): TestResponse
    {
        return $this->call('post', $path, $data + [csrf_token() => csrf_hash()]);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function stripToken(string $body): string
    {
        $body = (string) preg_replace('/value="[a-f0-9]{32,}"/', 'value="T"', $body);

        return (string) preg_replace('/[a-f0-9]{64}/', 'T', $body);
    }

    public function testLoginPageRenders(): void
    {
        $result = $this->get('/login');
        $result->assertStatus(200);
        $body = $this->body($result);

        $this->assertStringContainsString('name="email"', $body);
        $this->assertStringContainsString('autocomplete="username"', $body);
        $this->assertStringContainsString('name="password"', $body);
        $this->assertStringContainsString('autocomplete="current-password"', $body);
        $this->assertStringContainsString('name="' . csrf_token() . '"', $body);
        $this->assertStringNotContainsString('register', strtolower($body));
        $this->assertMatchesRegularExpression('/<title>[^<]*· Work Tracker<\/title>/u', $body);
        $this->assertStringNotContainsString('class="app-tabbar', $body);
        $this->assertStringContainsString('data-submit-once', $body);
    }

    public function testCorrectPasswordGoesToPendingTwoFactor(): void
    {
        $this->createUser('owner@example.test');

        $result = $this->postLogin(['email' => 'Owner@Example.test ', 'password' => self::PASSWORD]);
        $result->assertStatus(302);
        $this->assertStringEndsWith('/2fa', $result->response()->getHeaderLine('Location'));
        $this->assertFalse(auth('session')->loggedIn());
        $authenticator = auth('session')->getAuthenticator();
        $this->assertTrue(method_exists($authenticator, 'isPending') && $authenticator->isPending());
        $this->assertIsInt(session('pending_since'));
    }

    public function testWrongPasswordGives422GenericMessage(): void
    {
        $this->createUser('owner@example.test');

        $result = $this->postLogin(['email' => 'owner@example.test', 'password' => 'Nespravne-Heslo-1!']);
        $result->assertStatus(422);
        $body = $this->body($result);

        $this->assertStringContainsString(esc(lang('Login.errorInvalid')), $body);
        $this->assertStringContainsString(esc('owner@example.test', 'attr'), $body);
        $this->assertStringNotContainsString('Nespravne-Heslo-1!', $body);
    }

    public function testUnknownEmailLooksIdenticalToWrongPassword(): void
    {
        $this->createUser('owner@example.test');

        $known     = $this->postLogin(['email' => 'owner@example.test', 'password' => 'Nespravne-Heslo-1!']);
        $knownBody = $this->body($known);
        $missing   = $this->postLogin(['email' => 'nikto@example.test', 'password' => 'Nespravne-Heslo-1!']);

        $missing->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Login.errorInvalid')), $this->body($missing));
        $this->assertSame(
            $this->stripToken($knownBody),
            str_replace(esc('nikto@example.test', 'attr'), esc('owner@example.test', 'attr'), $this->stripToken($this->body($missing))),
        );
    }

    public function testDummyHashIsArgon2id(): void
    {
        $this->assertStringStartsWith('$argon2id$v=19$m=19456,t=2,p=1$', Login::DUMMY_HASH);
    }

    public function testInvalidInputGives422(): void
    {
        foreach (['', 'abc'] as $email) {
            $this->postLogin(['email' => $email, 'password' => 'x'])->assertStatus(422);
        }
    }

    public function testLoggedInAdminIsRedirectedFromLogin(): void
    {
        $this->loginAs($this->createUser());

        $result = $this->get('/login');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/', $result->response()->getHeaderLine('Location'));
    }

    public function testPendingUserCanLoginAgain(): void
    {
        $user = $this->createUser('owner@example.test');
        $this->startPending($user);

        $result = $this->postLogin(['email' => 'owner@example.test', 'password' => self::PASSWORD]);
        $result->assertStatus(302);
        $this->assertStringEndsWith('/2fa', $result->response()->getHeaderLine('Location'));
    }

    public function testLogoutWhenLoggedIn(): void
    {
        $this->loginAs($this->createUser());

        $result = $this->postLogin([], '/logout');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testLogoutWhenPending(): void
    {
        $this->startPending($this->createUser());

        $result = $this->postLogin([], '/logout');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        $authenticator = auth('session')->getAuthenticator();
        $this->assertFalse(method_exists($authenticator, 'isPending') && $authenticator->isPending());
    }

    public function testEnglishAcceptLanguage(): void
    {
        $body = $this->body($this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])->get('/login'));

        $this->assertStringContainsString('<html lang="en"', $body);
        $this->assertStringContainsString(lang('Login.heading', [], 'en'), $body);
    }

    public function testUnsupportedAcceptLanguageFallsBackToSlovak(): void
    {
        $result = $this->withHeaders(['Accept-Language' => 'pl-PL,pl;q=0.9'])->get('/login');
        $result->assertStatus(200);
        $body = $this->body($result);

        $this->assertStringContainsString('<html lang="sk"', $body);
        $this->assertStringContainsString(lang('Login.heading', [], 'sk'), $body);
    }

    public function testNoAcceptLanguageIsSlovak(): void
    {
        $this->assertStringContainsString('<html lang="sk"', $this->body($this->get('/login')));
    }

    public function testUserLocaleWinsAfterLogin(): void
    {
        $user = $this->createUser();
        $this->owner()->query("UPDATE user_security SET locale = 'en' WHERE user_id = " . (int) $user->id);
        $this->loginAs($user);

        $this->assertStringContainsString('<html lang="en"', $this->body($this->get('/')));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        return $this->owner()->query('SELECT event, ip FROM auth_events ORDER BY id')->getResultArray();
    }

    private function countEvent(string $event): int
    {
        return count(array_filter($this->events(), static fn (array $r): bool => $r['event'] === $event));
    }

    public function testFiveFailuresLockAccountEvenForCorrectPassword(): void
    {
        $this->createUser('owner@example.test');
        config('Email')->transport = 'smtp';
        $this->mockEmail();

        $last = null;

        for ($i = 0; $i < 5; $i++) {
            $last = $this->postLogin(['email' => 'owner@example.test', 'password' => 'Nespravne-Heslo-1!']);
        }

        $last->assertStatus(429);
        $this->assertStringContainsString(esc(lang('Login.errorLocked')), $this->body($last));

        $after = $this->postLogin(['email' => 'owner@example.test', 'password' => self::PASSWORD]);
        $after->assertStatus(429);
        $authenticator = auth('session')->getAuthenticator();
        $this->assertFalse(method_exists($authenticator, 'isPending') && $authenticator->isPending());

        $this->assertSame(5, $this->countEvent('login_password_fail'));
        $this->assertSame(1, $this->countEvent('lockout'));
        $this->assertSame(1, $this->countEvent('locked_attempt'));

        $mail = service('email');
        $this->assertInstanceOf(MockEmail::class, $mail);
        $this->assertContains('owner@example.test', (array) $mail->archive['recipients']);
    }

    public function testLockedUnknownEmailGives429WithoutMail(): void
    {
        config('Email')->transport = 'smtp';
        $this->mockEmail();
        $last = null;

        for ($i = 0; $i < 5; $i++) {
            $last = $this->postLogin(['email' => 'nikto@example.test', 'password' => 'Nespravne-Heslo-1!']);
        }

        $last->assertStatus(429);
        $this->assertSame(1, $this->countEvent('lockout'));
        $mail = service('email');
        $this->assertInstanceOf(MockEmail::class, $mail);
        $this->assertEmpty($mail->archive);
    }

    public function testSuccessfulPasswordIsAuditedAndKeepsCounters(): void
    {
        $this->createUser('owner@example.test');
        $this->postLogin(['email' => 'owner@example.test', 'password' => 'Nespravne-Heslo-1!']);
        $this->postLogin(['email' => 'owner@example.test', 'password' => self::PASSWORD])->assertStatus(302);

        $this->assertSame(1, $this->countEvent('login_password_ok'));
        $count = $this->owner()->query("SELECT fail_count FROM auth_lockouts WHERE kind = 'account'")->getRow();
        $this->assertSame(1, (int) $count->fail_count);
    }

    public function testRemoteAddrIsStoredAsIp(): void
    {
        service('superglobals')->setServer('REMOTE_ADDR', '203.0.113.9');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->createUser('owner@example.test');
        $this->postLogin(['email' => 'owner@example.test', 'password' => 'Nespravne-Heslo-1!']);

        $this->assertSame('203.0.113.9', $this->events()[0]['ip']);
    }
}
