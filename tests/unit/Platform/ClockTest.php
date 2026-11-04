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
        $this->assertSame(Clock::class, $a::class);
        $this->assertSame($a, service('clock'));
    }

    public function testApplicationTimezone(): void
    {
        $clock = service('clock');
        $this->assertSame('Europe/Bratislava', $clock->timezone()->getName());
        $this->assertSame('Europe/Bratislava', $clock->now()->getTimezone()->getName());
    }

    #[DataProvider('provideFrozenToday')]
    public function testFrozenToday(string $instant, string $expected): void
    {
        $this->assertSame($expected, (new FrozenClock($instant))->today());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideFrozenToday(): iterable
    {
        return [
            'CET after DST change'   => ['2026-10-31T23:30:00+00:00', '2026-11-01'],
            'CEST just before month' => ['2026-07-31T21:59:00+00:00', '2026-07-31'],
            'CEST month rollover'    => ['2026-07-31T22:00:00+00:00', '2026-08-01'],
        ];
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
        $this->assertSame($clock->now(), $clock->now());
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

    public function testParseInstantAcceptsTimestamptz(): void
    {
        $this->assertSame('2026-11-01T10:00:00+01:00', Clock::parseInstant('2026-11-01 10:00:00+01')->format('c'));
        $this->assertSame('2026-11-01T09:00:00+00:00', Clock::parseInstant('2026-11-01T09:00:00Z')->format('c'));
        $this->assertSame('2026-11-01T10:00:00+01:00', Clock::parseInstant('2026-11-01 10:00:00.123456+01:00')->format('c'));
    }

    #[DataProvider('provideParseInstantRejectsNonAbsolute')]
    public function testParseInstantRejectsNonAbsolute(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Clock::parseInstant($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideParseInstantRejectsNonAbsolute(): iterable
    {
        yield 'empty' => [''];

        yield 'now' => ['now'];

        yield 'relative' => ['+1 day'];

        yield 'no offset' => ['2026-11-01 10:00:00'];

        yield 'date only' => ['2026-11-01'];

        yield 'garbage' => ['garbage'];

        yield 'impossible date' => ['2026-13-45 10:00:00+01'];
    }
}
