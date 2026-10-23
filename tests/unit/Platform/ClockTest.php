<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Libraries\Platform\Clock;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class ClockTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testServiceReturnsSharedClock(): void
    {
        $a = service('clock');
        $this->assertInstanceOf(Clock::class, $a);
        $this->assertSame($a, service('clock'));
    }

    public function testApplicationTimezone(): void
    {
        $clock = service('clock');
        $this->assertSame('Europe/Bratislava', $clock->timezone()->getName());
        $this->assertSame('Europe/Bratislava', $clock->now()->getTimezone()->getName());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function boundaryProvider(): array
    {
        return [
            'CET after DST change'   => ['2026-10-31T23:30:00+00:00', '2026-11-01'],
            'CEST just before month' => ['2026-07-31T21:59:00+00:00', '2026-07-31'],
            'CEST month rollover'    => ['2026-07-31T22:00:00+00:00', '2026-08-01'],
        ];
    }

    #[DataProvider('boundaryProvider')]
    public function testFrozenToday(string $instant, string $expected): void
    {
        $this->assertSame($expected, (new FrozenClock($instant))->today());
    }

    public function testFrozenNowConvertedToAppTimezone(): void
    {
        $clock = new FrozenClock('2026-10-31T23:30:00+00:00');
        $this->assertSame('2026-11-01 00:30', $clock->now()->format('Y-m-d H:i'));
        $this->assertSame('Europe/Bratislava', $clock->now()->getTimezone()->getName());
    }

    public function testFrozenNowIsStable(): void
    {
        $clock = new FrozenClock('2026-10-15T08:00:00+02:00');
        $this->assertEquals($clock->now(), $clock->now());
    }

    public function testInjectMock(): void
    {
        Services::injectMock('clock', new FrozenClock('2026-10-15T08:00:00+02:00'));
        $this->assertSame('2026-10-15', service('clock')->today());
    }

    public function testInvalidInstantRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FrozenClock('not-a-date');
    }
}
