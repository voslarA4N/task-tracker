<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Libraries\Auth\LockoutService;
use App\Libraries\Platform\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class LockoutTest extends PgTestCase
{
    private const IP    = '203.0.113.9';
    private const EMAIL = 'owner@example.test';

    private LockoutService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner()->query('TRUNCATE auth_lockouts');
        $this->svc = new LockoutService($this->rw());
    }

    protected function tearDown(): void
    {
        $this->owner()->query('TRUNCATE auth_lockouts');
        parent::tearDown();
    }

    private function t0(): DateTimeImmutable
    {
        return (new FrozenClock('2026-10-24T10:00:00+02:00'))->now();
    }

    public function testFourFailuresDoNotLock(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse($this->svc->registerFailure(self::IP, self::EMAIL, $this->t0()));
        }
        $this->assertNull($this->svc->lockedUntil(self::IP, self::EMAIL, $this->t0()));
    }

    public function testFifthFailureLocksForFifteenMinutes(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->svc->registerFailure(self::IP, self::EMAIL, $this->t0());
        }
        $this->assertTrue($this->svc->registerFailure(self::IP, self::EMAIL, $this->t0()));

        $until = $this->svc->lockedUntil(self::IP, self::EMAIL, $this->t0());
        $this->assertNotNull($until);
        $this->assertSame($this->t0()->modify('+15 minutes')->getTimestamp(), $until->getTimestamp());
    }

    public function testFailureDuringLockDoesNotExtend(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->svc->registerFailure(self::IP, self::EMAIL, $this->t0());
        }
        $first = $this->svc->lockedUntil(self::IP, self::EMAIL, $this->t0());

        $later = $this->t0()->modify('+5 minutes');
        $this->assertFalse($this->svc->registerFailure(self::IP, self::EMAIL, $later));
        $second = $this->svc->lockedUntil(self::IP, self::EMAIL, $later);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->getTimestamp(), $second->getTimestamp());
    }

    public function testLockExpiresAfterFifteenMinutesAndOneSecond(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->svc->registerFailure(self::IP, self::EMAIL, $this->t0());
        }
        $this->assertNull($this->svc->lockedUntil(self::IP, self::EMAIL, $this->t0()->modify('+15 minutes +1 second')));
    }

    public function testNewWindowAfterPause(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->svc->registerFailure(self::IP, self::EMAIL, $this->t0());
        }
        $this->assertFalse($this->svc->registerFailure(self::IP, self::EMAIL, $this->t0()->modify('+16 minutes')));

        $row = $this->owner()->query("SELECT fail_count FROM auth_lockouts WHERE kind = 'account'")->getRow();
        $this->assertSame(1, (int) $row->fail_count);
    }

    public function testIpv6SharesSlash64(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->svc->registerFailure('2001:db8:1:2::1', 'a' . $i . '@example.test', $this->t0());
        }
        $this->assertNotNull($this->svc->lockedUntil('2001:db8:1:2:ffff::9', 'fresh@example.test', $this->t0()));
        $this->assertNull($this->svc->lockedUntil('2001:db8:1:3::1', 'fresh@example.test', $this->t0()));
    }

    public function testAccountLockIsCaseInsensitive(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->svc->registerFailure('203.0.113.' . (10 + $i), 'Owner@Example.test', $this->t0());
        }
        $this->assertNotNull($this->svc->lockedUntil('198.51.100.1', 'owner@example.test', $this->t0()));
    }

    public function testNonexistentEmailLocksToo(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->svc->registerFailure(self::IP, 'nikto@example.test', $this->t0());
        }
        $this->assertNotNull($this->svc->lockedUntil('198.51.100.7', 'nikto@example.test', $this->t0()));
    }

    public function testClearRemovesBoth(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->svc->registerFailure(self::IP, self::EMAIL, $this->t0());
        }
        $this->svc->clear(self::IP, self::EMAIL);

        $this->assertNull($this->svc->lockedUntil(self::IP, self::EMAIL, $this->t0()));
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM auth_lockouts')->getRow()->c);
    }
}
