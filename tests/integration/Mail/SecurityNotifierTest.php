<?php

declare(strict_types=1);

namespace Tests\Integration\Mail;

use App\Libraries\Auth\SecurityNotifier;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockEmail;
use CodeIgniter\Test\TestLogger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Bezpecnostne upozornenia: doruceni cez CI4 Email a metadata-only log (D-13, D-14, O-3).
 *
 * @internal
 */
#[Group('integration')]
final class SecurityNotifierTest extends CIUnitTestCase
{
    private const TO = 'owner@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config('Email')->transport = 'smtp';
        $this->mockEmail();
    }

    protected function tearDown(): void
    {
        config('Email')->transport = 'smtp';
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'at'      => (new FrozenClock('2026-10-24T10:00:00+02:00'))->now(),
            'ip'      => '203.0.113.7',
            'minutes' => 15,
        ];
    }

    private function mail(): MockEmail
    {
        $mail = service('email');
        $this->assertInstanceOf(MockEmail::class, $mail);

        return $mail;
    }

    public function testLockedMailInSlovak(): void
    {
        (new SecurityNotifier())->notify('account_locked', self::TO, 'sk', $this->context());

        $archive = $this->mail()->archive;
        $this->assertStringContainsString(self::TO, (string) $archive['recipients']);
        $this->assertStringContainsString('Work Tracker', (string) $archive['subject']);
        $this->assertStringContainsString('203.0.113.7', (string) $archive['body']);
        $this->assertStringContainsString('15', (string) $archive['body']);
        $this->assertStringNotContainsString('Notify.', (string) $archive['body']);
        $this->assertStringNotContainsString('Notify.', (string) $archive['subject']);
    }

    public function testEnglishSubjectDiffersFromSlovak(): void
    {
        $n = new SecurityNotifier();
        $n->notify('account_locked', self::TO, 'sk', $this->context());
        $sk = (string) $this->mail()->archive['subject'];
        $n->notify('account_locked', self::TO, 'en', $this->context());
        $en = (string) $this->mail()->archive['subject'];

        $this->assertNotSame($sk, $en);
        $this->assertStringContainsString('Work Tracker', $en);
        $this->assertStringNotContainsString('Notify.', $en);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function kinds(): array
    {
        return [
            'password_changed'         => ['password_changed'],
            'password_reset'           => ['password_reset'],
            'totp_reset'               => ['totp_reset'],
            'backup_codes_regenerated' => ['backup_codes_regenerated'],
            'device_trusted'           => ['device_trusted'],
            'account_locked'           => ['account_locked'],
        ];
    }

    #[DataProvider('kinds')]
    public function testEveryKindHasSubjectAndSafeBody(string $kind): void
    {
        (new SecurityNotifier())->notify($kind, self::TO, 'sk', $this->context());

        $archive = $this->mail()->archive;
        $this->assertNotSame('', trim((string) $archive['subject']));
        $this->assertNotSame('', trim((string) $archive['body']));
        $this->assertStringNotContainsString('Notify.', (string) $archive['subject'] . $archive['body']);
        $this->assertStringNotContainsStringIgnoringCase('http', (string) $archive['body']);
        $this->assertStringNotContainsStringIgnoringCase('token', (string) $archive['body']);
    }

    public function testUnknownContextKeysAreIgnored(): void
    {
        (new SecurityNotifier())->notify('account_locked', self::TO, 'sk', $this->context() + ['token' => 'TAJNE123']);

        $this->assertStringNotContainsString('TAJNE123', (string) $this->mail()->archive['body']);
    }

    public function testUnknownKindThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SecurityNotifier())->notify('foo', self::TO, 'sk');
    }

    public function testLogTransportSendsNothingAndLogsMetadataOnly(): void
    {
        config('Email')->transport = 'log';

        (new SecurityNotifier())->notify('account_locked', self::TO, 'sk', $this->context());

        $this->assertNull($this->mail()->archive ?? null);
        $this->assertTrue(
            TestLogger::didLog('info', 'mail.suppressed kind=security.account_locked to=owner@example.test', false),
            'Ocakavany metadata riadok nebol zalogovany',
        );
        $this->assertNotLogged203();
    }

    private function assertNotLogged203(): void
    {
        foreach (TestLogger::$op_logs as $log) {
            $this->assertStringNotContainsString('203.0.113.7', (string) $log['message']);
        }
    }
}
