<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Libraries\Auth\BackupCodeService;
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
final class BackupCodesFlowTest extends AuthTestCase
{
    private const T0 = '2026-10-24T10:00:00+02:00';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock(self::T0);
        Services::injectMock('clock', $this->clock);
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function fetch(string $path): TestResponse
    {
        $this->withSession();

        return $this->get($path);
    }

    private function post(string $path, array $data): TestResponse
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

    private function advance(int $seconds): void
    {
        $this->clock = new FrozenClock($this->clock->now()->modify('+' . $seconds . ' seconds')->format(DATE_ATOM));
        Services::injectMock('clock', $this->clock);
    }

    /**
     * Prve nastavenie az po aktivaciu; vrati kody zobrazene na stranke.
     *
     * @return list<string>
     */
    private function activate(int $pendingAge = 0): array
    {
        $user = $this->createUser('owner@example.test');
        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp() - $pendingAge);
        $this->fetch('/2fa/setup');
        $secret = (new TotpService())->decryptSecret((string) session('totp_setup_secret'));

        $result = $this->post('/2fa/setup', ['code' => $this->totpCode($secret)]);
        $result->assertStatus(302);
        $this->assertStringEndsWith('/2fa/backup-codes', $result->response()->getHeaderLine('Location'));

        return $this->codesFromDb((int) $user->id);
    }

    /**
     * Kody su v DB len ako hash, preto ich zistime zo stranky.
     *
     * @return list<string>
     */
    private function codesFromDb(int $userId): array
    {
        unset($userId);
        $body = $this->body($this->fetch('/2fa/backup-codes'));
        preg_match_all('/<code>([^<]+)<\/code>/', $body, $m);

        return $m[1];
    }

    public function testActivationRefreshesPendingWindow(): void
    {
        $this->activate(1000);

        $this->assertSame($this->clock->now()->getTimestamp(), session('pending_since'));
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testCodesPageRendersTenCodesAndDisabledContinue(): void
    {
        $codes = $this->activate();
        $this->assertCount(10, $codes);

        $body = $this->body($this->fetch('/2fa/backup-codes'));
        $this->assertStringContainsString(esc(lang('TwoFactor.codesWarning')), $body);
        $this->assertStringContainsString('download="work-tracker-zalozne-kody.txt"', $body);
        $this->assertStringContainsString('name="acknowledged"', $body);
        $this->assertMatchesRegularExpression('/<button[^>]*\bdisabled\b/', $body);
        $this->assertStringNotContainsString('/logout', $body);
    }

    public function testTxtDownload(): void
    {
        $codes  = $this->activate();
        $result = $this->fetch('/2fa/backup-codes.txt');

        $result->assertStatus(200);
        $this->assertStringContainsString('text/plain', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment', $result->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('work-tracker-zalozne-kody.txt', $result->response()->getHeaderLine('Content-Disposition'));
        $body = $this->body($result);
        $this->assertStringContainsString('owner@example.test', $body);

        foreach ($codes as $code) {
            $this->assertStringContainsString($code, $body);
        }
    }

    public function testPageStillWorksAfter1000SecondsAndAcknowledgeLogsIn(): void
    {
        $this->activate();
        $this->advance(1000);

        $this->fetch('/2fa/backup-codes')->assertStatus(200);
        $result = $this->post('/2fa/backup-codes', ['acknowledged' => '1']);
        $result->assertStatus(302);
        $this->assertTrue(auth('session')->loggedIn());
    }

    public function testPageExpiresAfter1801Seconds(): void
    {
        $this->activate();
        $this->advance(1801);

        $result = $this->fetch('/2fa/backup-codes');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        $this->assertSame(lang('TwoFactor.pendingExpired'), session()->getFlashdata('auth_info'));
    }

    public function testConfirmWithoutAcknowledgeIs422(): void
    {
        $this->activate();

        $result = $this->post('/2fa/backup-codes', []);
        $result->assertStatus(422);
        $this->assertFalse(auth('session')->loggedIn());
        $this->assertStringContainsString(esc(lang('TwoFactor.codesAckRequired')), $this->body($result));
    }

    public function testConfirmLogsInAndCodesNeverShownAgain(): void
    {
        $this->activate();

        $result = $this->post('/2fa/backup-codes', ['acknowledged' => '1']);
        $result->assertStatus(302);
        $this->assertStringEndsWith('/', $result->response()->getHeaderLine('Location'));
        $this->assertTrue(auth('session')->loggedIn());

        $again = $this->fetch('/2fa/backup-codes');
        $again->assertStatus(302);
    }

    private function loginWithBackup(User $user): void
    {
        $this->owner()->query('TRUNCATE auth_lockouts');
        auth('session')->logout();
        $_SESSION = [];
        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());
    }

    /**
     * @return array{User, list<string>}
     */
    private function userWithCodes(): array
    {
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $codes = (new BackupCodeService($this->rw()))->generate((int) $user->id, service('clock')->now());
        $this->startPending($user);
        session()->set('pending_since', service('clock')->now()->getTimestamp());

        return [$user, $codes];
    }

    public function testVerifyPageLinksToBackup(): void
    {
        $this->userWithCodes();

        $this->assertStringContainsString('/2fa/backup', $this->body($this->fetch('/2fa/verify')));
        $this->fetch('/2fa/backup')->assertStatus(200);
    }

    public function testBackupCodeLogsInOnceAndShowsRemaining(): void
    {
        [, $codes] = $this->userWithCodes();

        $result = $this->post('/2fa/backup', ['code' => $codes[0]]);
        $result->assertStatus(302);
        $this->assertStringEndsWith('/', $result->response()->getHeaderLine('Location'));
        $this->assertTrue(auth('session')->loggedIn());
        $this->assertSame(1, $this->eventCount('backup_code_used'));

        $notice = session()->getFlashdata('app_notice');
        $this->assertIsArray($notice);
        $this->assertSame(lang('TwoFactor.backupUsed', [9]), $notice['message']);
    }

    public function testReusedBackupCodeIs422(): void
    {
        [$user, $codes] = $this->userWithCodes();
        $this->post('/2fa/backup', ['code' => $codes[0]])->assertStatus(302);

        $this->loginWithBackup($user);
        $this->post('/2fa/backup', ['code' => $codes[0]])->assertStatus(422);
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testFiveWrongBackupCodesLock(): void
    {
        $this->userWithCodes();

        for ($i = 0; $i < 4; $i++) {
            $this->post('/2fa/backup', ['code' => 'AAAAA-AAAAA'])->assertStatus(422);
        }
        $this->post('/2fa/backup', ['code' => 'AAAAA-AAAAA'])->assertStatus(429);
    }
}
