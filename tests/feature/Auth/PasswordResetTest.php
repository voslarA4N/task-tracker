<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Libraries\Auth\AccountRecovery;
use App\Libraries\Auth\PasswordResetService;
use App\Libraries\Auth\TrustedDeviceService;
use App\Libraries\Platform\AfterResponse;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\Mock\MockEmail;
use CodeIgniter\Test\TestLogger;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Obnova hesla e-mailom (AUTH-06, D-12..D-14, D-17): nikdy neprihlasi, 2FA ostava povinne.
 *
 * @internal
 */
#[Group('feature')]
final class PasswordResetTest extends AuthTestCase
{
    private const T0           = '2026-10-24T10:00:00+02:00';
    private const EMAIL        = 'owner@example.test';
    private const NEW_PASSWORD = 'Nove-Heslo-Slnko-2026!';

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
        config('Email')->transport = 'smtp';
        Services::reset();
        parent::tearDown();
    }

    private function setClock(string $instant): void
    {
        $this->clock = new FrozenClock($instant);
        Services::injectMock('clock', $this->clock);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    /**
     * @param array<string, string> $data
     */
    private function postTo(string $path, array $data): TestResponse
    {
        $this->withSession();

        return $this->call('post', $path, $data + [csrf_token() => csrf_hash()]);
    }

    private function mail(): MockEmail
    {
        $mail = service('email');
        $this->assertInstanceOf(MockEmail::class, $mail);

        return $mail;
    }

    private function tokenCount(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM password_resets')->getRow()->c;
    }

    private function eventCount(string $event): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM auth_events WHERE event = ?', [$event])->getRow()->c;
    }

    /**
     * Vyziada reset a vrati otvoreny token z e-mailu.
     */
    private function requestToken(): string
    {
        $this->postTo('/password/forgot', ['email' => self::EMAIL])->assertStatus(200);
        $this->assertSame(1, preg_match('#/password/reset/([0-9a-f]{64})#', (string) $this->mail()->archive['body'], $m));

        return $m[1];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function ownerWithTotp(): array
    {
        $user   = $this->createUser(self::EMAIL);
        $secret = $this->enrollTotp($user);

        return [$user, $secret];
    }

    public function testLoginPageLinksToForgotPassword(): void
    {
        $body = $this->body($this->get('/login'));

        $this->assertStringContainsString('href="/password/forgot"', $body);
        $this->assertStringContainsString(esc(lang('PasswordReset.forgotLink')), $body);
    }

    public function testForgotFormRenders(): void
    {
        $result = $this->get('/password/forgot');
        $result->assertStatus(200);
        $this->assertStringContainsString('name="email"', $this->body($result));
    }

    public function testRequestStoresOnlyHashAndExpiresInSixtyMinutes(): void
    {
        $this->ownerWithTotp();

        $result = $this->postTo('/password/forgot', ['email' => self::EMAIL]);
        $result->assertStatus(200);
        $this->assertStringContainsString(esc(lang('PasswordReset.sent')), $this->body($result));

        $archive = $this->mail()->archive;
        $this->assertSame(1, preg_match('#/password/reset/([0-9a-f]{64})#', (string) $archive['body'], $m));
        $token = $m[1];

        $row = $this->owner()->query('SELECT token_hash, EXTRACT(EPOCH FROM expires_at)::bigint AS e FROM password_resets')->getRowArray();
        $this->assertSame(hash('sha256', $token), trim((string) $row['token_hash']));
        $this->assertSame($this->clock->now()->getTimestamp() + 3600, (int) $row['e']);
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM password_resets WHERE token_hash = ?', [$token])->getRow()->c);
        $this->assertSame(1, $this->eventCount('password_reset_requested'));
    }

    public function testUnknownEmailGetsIdenticalAnswerAndNoMail(): void
    {
        $this->ownerWithTotp();

        $known = $this->body($this->postTo('/password/forgot', ['email' => self::EMAIL]));
        $this->mockEmail();
        $unknown = $this->postTo('/password/forgot', ['email' => 'nobody@example.test']);

        $unknown->assertStatus(200);
        $this->assertSame(
            preg_replace('#[0-9a-f]{64}#', '', $known),
            preg_replace('#[0-9a-f]{64}#', '', $this->body($unknown)),
        );
        $this->assertEmpty($this->mail()->archive ?? null);
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM password_resets')->getRow()->c);
    }

    public function testLogTransportNeverLogsTokenOrBody(): void
    {
        $this->ownerWithTotp();
        config('Email')->transport = 'log';

        $this->postTo('/password/forgot', ['email' => self::EMAIL])->assertStatus(200);

        $this->assertTrue(TestLogger::didLog('info', 'mail.suppressed kind=password_reset', false));
        $token = (string) $this->owner()->query('SELECT token_hash FROM password_resets')->getRow()->token_hash;
        $this->assertFalse(TestLogger::didLog('info', '/password/reset/', false));
        $this->assertFalse(TestLogger::didLog('info', trim($token), false));
    }

    public function testResetFormShowsTwoNewPasswordFields(): void
    {
        $this->ownerWithTotp();
        $token = $this->requestToken();

        $result = $this->get('/password/reset/' . $token);
        $result->assertStatus(200);
        $this->assertSame(2, substr_count($this->body($result), 'autocomplete="new-password"'));
    }

    public function testMismatchAndWeakPasswordsAreRejectedWithoutConsumingToken(): void
    {
        $this->ownerWithTotp();
        $token = $this->requestToken();

        $this->postTo('/password/reset/' . $token, ['password' => self::NEW_PASSWORD, 'password_confirm' => 'ina-hodnota-123'])->assertStatus(422);
        $this->postTo('/password/reset/' . $token, ['password' => 'kratke', 'password_confirm' => 'kratke'])->assertStatus(422);

        $this->get('/password/reset/' . $token)->assertStatus(200);
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM password_resets WHERE used_at IS NOT NULL')->getRow()->c);
    }

    public function testResetNeverLogsInAndRequiresTotpAfterwards(): void
    {
        [$user, $secret] = $this->ownerWithTotp();
        $deviceToken     = $this->trustDevice($user);
        $epoch           = (new TrustedDeviceService())->currentEpoch((int) $user->id);
        $token           = $this->requestToken();
        $this->mockEmail();

        $result = $this->postTo('/password/reset/' . $token, ['password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);

        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        $this->assertSame(lang('PasswordReset.done'), session()->getFlashdata('auth_info'));
        $this->assertFalse(auth('session')->loggedIn());
        $authenticator = auth('session')->getAuthenticator();
        $this->assertInstanceOf(Session::class, $authenticator);
        $this->assertFalse($authenticator->isPending());

        $this->assertSame($epoch + 1, (new TrustedDeviceService())->currentEpoch((int) $user->id));
        $this->assertNotNull($this->owner()->query('SELECT revoked_at FROM trusted_devices WHERE user_id = ?', [$user->id])->getRow()->revoked_at);
        $this->assertNull((new TrustedDeviceService())->findValid($deviceToken, $this->clock->now()));
        $this->assertSame(1, $this->eventCount('password_reset_done'));
        $this->assertSame([self::EMAIL], $this->mail()->archive['recipients'] ?? null);

        // Nove heslo vedie na /2fa, nie priamo dnu.
        $login = $this->postTo('/login', ['email' => self::EMAIL, 'password' => self::NEW_PASSWORD]);
        $login->assertStatus(302);
        $this->assertStringEndsWith('/2fa', $login->response()->getHeaderLine('Location'));
        $this->assertNotSame('', $secret);
    }

    public function testOldPasswordNoLongerWorks(): void
    {
        $this->ownerWithTotp();
        $token = $this->requestToken();
        $this->postTo('/password/reset/' . $token, ['password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD])->assertStatus(302);
        auth('session')->logout();
        $this->withSession([]);

        $this->postTo('/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertStatus(422);
    }

    public function testTokenIsSingleUse(): void
    {
        $this->ownerWithTotp();
        $token = $this->requestToken();
        $this->postTo('/password/reset/' . $token, ['password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD])->assertStatus(302);

        $get = $this->get('/password/reset/' . $token);
        $this->assertStringContainsString(esc(lang('PasswordReset.invalid')), $this->body($get));

        $post = $this->postTo('/password/reset/' . $token, ['password' => 'Uplne-Ine-Heslo-2026!', 'password_confirm' => 'Uplne-Ine-Heslo-2026!']);
        $this->assertStringContainsString(esc(lang('PasswordReset.invalid')), $this->body($post));
    }

    public function testExpiredAndTamperedTokensAreInvalid(): void
    {
        $this->ownerWithTotp();
        $token = $this->requestToken();

        $tampered = substr($token, 0, 63) . ($token[63] === 'a' ? 'b' : 'a');
        $this->assertStringContainsString(esc(lang('PasswordReset.invalid')), $this->body($this->get('/password/reset/' . $tampered)));

        $this->setClock($this->clock->now()->modify('+61 minutes')->format(DATE_ATOM));
        $this->assertStringContainsString(esc(lang('PasswordReset.invalid')), $this->body($this->get('/password/reset/' . $token)));
        $post = $this->postTo('/password/reset/' . $token, ['password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        $this->assertStringContainsString(esc(lang('PasswordReset.invalid')), $this->body($post));
    }

    public function testNewRequestKeepsPreviousTokenValid(): void
    {
        $this->ownerWithTotp();
        $first  = $this->requestToken();
        $second = $this->requestToken();

        $this->assertNotSame($first, $second);
        $this->assertStringNotContainsString(esc(lang('PasswordReset.invalid')), $this->body($this->get('/password/reset/' . $first)));
        $this->assertStringNotContainsString(esc(lang('PasswordReset.invalid')), $this->body($this->get('/password/reset/' . $second)));
    }

    public function testSuccessfulResetInvalidatesOtherOutstandingTokens(): void
    {
        $this->ownerWithTotp();
        $first  = $this->requestToken();
        $second = $this->requestToken();

        $this->postTo('/password/reset/' . $second, ['password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD])->assertStatus(302);

        $this->assertStringContainsString(esc(lang('PasswordReset.invalid')), $this->body($this->get('/password/reset/' . $first)));
    }

    public function testRequestsPerAccountAreCappedPerHour(): void
    {
        $this->ownerWithTotp();

        for ($i = 0; $i < PasswordResetService::MAX_PER_ACCOUNT_HOUR + 2; $i++) {
            $result = $this->postTo('/password/forgot', ['email' => self::EMAIL]);
            $result->assertStatus(200);
            $this->assertStringContainsString(esc(lang('PasswordReset.sent')), $this->body($result));
        }

        $this->assertSame(PasswordResetService::MAX_PER_ACCOUNT_HOUR, $this->tokenCount());
        $this->assertSame(PasswordResetService::MAX_PER_ACCOUNT_HOUR, $this->eventCount('password_reset_requested'));

        // Po hodine sa limit uvolni.
        $this->setClock($this->clock->now()->modify('+61 minutes')->format(DATE_ATOM));
        $this->mockEmail();
        $this->requestToken();
        $this->assertSame(PasswordResetService::MAX_PER_ACCOUNT_HOUR + 1, $this->eventCount('password_reset_requested'));
    }

    public function testRequestsPerIpAreCappedAcrossAccounts(): void
    {
        $this->ownerWithTotp();
        $other = $this->createUser('accountant@example.test', 'accountant');
        $this->enrollTotp($other);

        for ($i = 0; $i < PasswordResetService::MAX_PER_ACCOUNT_HOUR; $i++) {
            $this->postTo('/password/forgot', ['email' => self::EMAIL])->assertStatus(200);
        }

        for ($i = 0; $i < PasswordResetService::MAX_PER_ACCOUNT_HOUR; $i++) {
            $this->postTo('/password/forgot', ['email' => 'accountant@example.test'])->assertStatus(200);
        }

        $this->assertSame(PasswordResetService::MAX_PER_IP_HOUR, $this->tokenCount());
    }

    public function testAccountLookupAndMailRunOnlyAfterResponse(): void
    {
        $this->ownerWithTotp();
        $after = new AfterResponse(true);
        Services::injectMock('afterResponse', $after);

        $known = $this->postTo('/password/forgot', ['email' => self::EMAIL]);
        $known->assertStatus(200);
        $unknown = $this->postTo('/password/forgot', ['email' => 'nobody@example.test']);
        $unknown->assertStatus(200);

        // Pred odoslanim odpovede sa pre existujuci ucet neurobilo nic navyse (ziadny token, ziadny e-mail).
        $this->assertSame(
            preg_replace('#[0-9a-f]{64}#', '', $this->body($known)),
            preg_replace('#[0-9a-f]{64}#', '', $this->body($unknown)),
        );
        $this->assertSame(0, $this->tokenCount());
        $this->assertEmpty($this->mail()->archive ?? null);
        $this->assertSame(2, $after->pending());

        $after->flush();

        $this->assertSame(1, $this->tokenCount());
        $this->assertSame([self::EMAIL], $this->mail()->archive['recipients'] ?? null);
        $this->assertSame(1, $this->eventCount('password_reset_requested'));
    }

    public function testOtherLoggedInSessionIsEndedByReset(): void
    {
        [$user] = $this->ownerWithTotp();
        $this->loginAs($user);
        $this->get('/')->assertStatus(200);

        $token = $this->requestToken();
        $this->postTo('/password/reset/' . $token, ['password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);

        // Relacia pred resetom (stara epocha) uz neplati.
        $this->actingAs($user);
        $this->withSession(['auth_epoch' => 0, 'last_activity' => $this->clock->now()->getTimestamp()]);
        $result = $this->get('/');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testAccountWithoutTotpGetsTokenAndCanReset(): void
    {
        $this->createUser(self::EMAIL);

        $token = $this->requestToken();
        $this->assertSame(1, $this->tokenCount());
        $this->assertSame(1, $this->eventCount('password_reset_requested'));

        $this->assertStringNotContainsString(esc(lang('PasswordReset.invalid')), $this->body($this->get('/password/reset/' . $token)));
        $post = $this->postTo('/password/reset/' . $token, ['password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        $post->assertStatus(302);
        $this->assertStringEndsWith('/login', $post->response()->getHeaderLine('Location'));
    }

    public function testTwoFactorResetDeletesUnusedTokens(): void
    {
        [$user] = $this->ownerWithTotp();
        $this->requestToken();

        // AccountRecovery::resetTwoFactor nepouzite tokeny rovno zmaze.
        (new AccountRecovery())->resetTwoFactor($user, $this->clock->now());
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM password_resets WHERE used_at IS NULL')->getRow()->c);
    }
}
