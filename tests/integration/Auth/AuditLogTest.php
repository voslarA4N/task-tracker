<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Libraries\Auth\AuditLog;
use App\Libraries\Auth\AuthEvent;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class AuditLogTest extends PgTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->owner()->query('TRUNCATE auth_events');
    }

    protected function tearDown(): void
    {
        $this->owner()->query('TRUNCATE auth_events');
        parent::tearDown();
    }

    public function testRecordStoresHashedIdentifierOnly(): void
    {
        (new AuditLog($this->rw()))->record(AuthEvent::LoginPasswordFail, false, null, 'x@example.test');

        $row = $this->owner()->query('SELECT * FROM auth_events')->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('login_password_fail', $row['event']);
        $this->assertContains($row['success'], [false, 'f']);
        $this->assertNotSame('', (string) $row['ip']);
        $this->assertSame(hash('sha256', 'x@example.test'), $row['identifier_hash']);
        foreach ($row as $value) {
            $this->assertStringNotContainsString('x@example.test', (string) $value);
        }
    }

    public function testDeviceLabelFromIphoneUserAgent(): void
    {
        service('superglobals')->setServer('HTTP_USER_AGENT', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

        (new AuditLog($this->rw()))->record(AuthEvent::Logout, true, null);

        $row = $this->owner()->query('SELECT device_label FROM auth_events')->getRow();
        $this->assertStringContainsString('iPhone', (string) $row->device_label);
    }

    public function testAppRwCannotUpdateOrDelete(): void
    {
        (new AuditLog($this->rw()))->record(AuthEvent::Logout, true, null);

        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE auth_events SET success = false"));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM auth_events'));
    }

    public function testDetailIsTruncatedTo200(): void
    {
        (new AuditLog($this->rw()))->record(AuthEvent::Lockout, false, null, null, str_repeat('a', 500));

        $row = $this->owner()->query('SELECT length(detail) AS l FROM auth_events')->getRow();
        $this->assertSame(200, (int) $row->l);
    }
}
