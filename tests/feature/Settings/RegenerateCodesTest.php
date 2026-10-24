<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Auth\BackupCodeService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\Mock\MockEmail;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Pregenerovanie zaloznych kodov so znovuoverenim heslom (AUTH-03, D-11, D-20).
 *
 * @internal
 */
#[Group('feature')]
final class RegenerateCodesTest extends AuthTestCase
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

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    /**
     * @param array<string, string> $data
     */
    private function postTo(string $path, array $data = []): TestResponse
    {
        $this->withSession();

        return $this->call('post', $path, $data + [csrf_token() => csrf_hash()]);
    }

    private function fetch(string $path): TestResponse
    {
        $this->withSession();

        return $this->get($path);
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

    /**
     * @return array{0: User, 1: list<string>}
     */
    private function ownerWithCodes(): array
    {
        $user = $this->createUser(self::EMAIL);
        $this->enrollTotp($user);
        $old = (new BackupCodeService())->generate((int) $user->id, service('clock')->now());
        $this->loginAs($user);

        return [$user, $old];
    }

    public function testCardHasRegenerateButtonAndModal(): void
    {
        $this->ownerWithCodes();

        $html = $this->body($this->fetch('/settings/security'));

        $this->assertStringContainsString('btn-outline-danger', $html);
        $this->assertStringContainsString('data-bs-target="#regenerate-codes"', $html);
        $this->assertStringContainsString('id="regenerate-codes"', $html);
        $this->assertStringContainsString(esc(lang('Account.regenerateConfirm')), $html);
        $this->assertStringContainsString('action="/settings/security/backup-codes"', $html);
        $this->assertStringContainsString('10 z 10', $html);
    }

    public function testWrongPasswordKeepsOldCodes(): void
    {
        [$user, $old] = $this->ownerWithCodes();

        $result = $this->postTo('/settings/security/backup-codes', ['current_password' => 'zle-heslo-123456']);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Account.reauthFailed')), $this->body($result));
        $this->assertTrue((new BackupCodeService())->consume((int) $user->id, $old[0], service('clock')->now()));
        $this->assertSame(0, $this->eventCount('backup_codes_regenerated'));
    }

    public function testSuccessShowsNewCodesOnceAndInvalidatesOld(): void
    {
        [$user, $old] = $this->ownerWithCodes();
        $this->mockEmail();

        $result = $this->postTo('/settings/security/backup-codes', ['current_password' => self::PASSWORD]);

        $result->assertStatus(302);
        $this->assertStringEndsWith('/settings/security/backup-codes', $result->response()->getHeaderLine('Location'));
        $this->assertSame(1, $this->eventCount('backup_codes_regenerated'));
        $this->assertSame([self::EMAIL], $this->mail()->archive['recipients'] ?? null);

        $page = $this->fetch('/settings/security/backup-codes');
        $page->assertStatus(200);
        $html = $this->body($page);
        $this->assertSame(10, preg_match_all('#<li><code>([A-Z0-9]{5}-[A-Z0-9]{5})</code></li>#', $html, $m));
        $this->assertStringContainsString('backup-codes.txt', $html);
        $this->assertStringContainsString('name="acknowledged"', $html);

        $svc = new BackupCodeService();
        $now = service('clock')->now();
        $this->assertFalse($svc->consume((int) $user->id, $old[0], $now));
        $this->assertTrue($svc->consume((int) $user->id, $m[1][0], $now));
    }

    public function testTxtDownloadIsPlainTextWithCodes(): void
    {
        $this->ownerWithCodes();
        $this->postTo('/settings/security/backup-codes', ['current_password' => self::PASSWORD]);
        $html = $this->body($this->fetch('/settings/security/backup-codes'));
        preg_match('#<code>([A-Z0-9]{5}-[A-Z0-9]{5})</code>#', $html, $m);

        $txt = $this->fetch('/settings/security/backup-codes.txt');

        $txt->assertStatus(200);
        $this->assertStringContainsString('text/plain', $txt->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('no-store', $txt->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString($m[1], $this->body($txt));
    }

    public function testConfirmRequiresAcknowledgementThenHidesCodes(): void
    {
        $this->ownerWithCodes();
        $this->postTo('/settings/security/backup-codes', ['current_password' => self::PASSWORD]);

        $this->postTo('/settings/security/backup-codes/done')->assertStatus(422);

        $done = $this->postTo('/settings/security/backup-codes/done', ['acknowledged' => '1']);
        $done->assertStatus(302);
        $this->assertStringEndsWith('/settings/security', $done->response()->getHeaderLine('Location'));

        $again = $this->fetch('/settings/security/backup-codes');
        $again->assertStatus(302);
        $this->assertStringEndsWith('/settings/security', $again->response()->getHeaderLine('Location'));
    }

    public function testRegenerationIsAuditedAndCountIsFull(): void
    {
        [$user] = $this->ownerWithCodes();
        $this->postTo('/settings/security/backup-codes', ['current_password' => self::PASSWORD]);

        $this->assertSame(10, (new BackupCodeService())->remaining((int) $user->id));
        $html = $this->body($this->fetch('/settings/security'));
        $this->assertStringContainsString('10 z 10', $html);
    }
}
