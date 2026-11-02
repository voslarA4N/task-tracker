<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Libraries\Reporting\LimitMath;
use App\Libraries\Reporting\LimitStatus;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class ThresholdTest extends CIUnitTestCase
{
    public function testPercent(): void
    {
        $this->assertSame('88.5', LimitMath::percent('44230.00', '50000.00'));
        $this->assertSame('0.0', LimitMath::percent('0.00', '50000.00'));
        $this->assertSame('112.4', LimitMath::percent('56200.00', '50000.00'));
        $this->assertSame('-0.2', LimitMath::percent('-100.00', '50000.00'));
    }

    public function testReachedEdges(): void
    {
        $this->assertFalse(LimitMath::reached('39980.00', '50000.00', 80));
        $this->assertSame('80.0', LimitMath::percent('39980.00', '50000.00'));
        $this->assertTrue(LimitMath::reached('40000.00', '50000.00', 80));
        $this->assertFalse(LimitMath::reached('49999.99', '50000.00', 100));
        $this->assertTrue(LimitMath::reached('50000.00', '50000.00', 100));
    }

    public function testLevel(): void
    {
        $this->assertSame('normal', LimitMath::level('39999.99', '50000.00', [80, 100]));
        $this->assertSame('warn', LimitMath::level('40000.00', '50000.00', [80, 100]));
        $this->assertSame('crit', LimitMath::level('50000.00', '50000.00', [80, 100]));
        $this->assertSame('normal', LimitMath::level('49000.00', '50000.00', [100]));
        $this->assertSame('crit', LimitMath::level('62500.00', '62500.00', [100]));
        $this->assertSame('normal', LimitMath::level('39999.99', '50000.00', [100, 80]));
        $this->assertSame('warn', LimitMath::level('40000.00', '50000.00', [100, 80]));
        $this->assertSame('crit', LimitMath::level('50000.00', '50000.00', [100, 80]));
        $this->assertSame('warn', LimitMath::level('42500.00', '50000.00', [50, 80, 100]));
    }

    public function testReachedThresholds(): void
    {
        $this->assertSame([80], LimitMath::reachedThresholds('45000.00', '50000.00', [100, 80]));
        $this->assertSame([80, 100], LimitMath::reachedThresholds('50000.00', '50000.00', [100, 80]));
    }

    public function testRemainingAndExceeded(): void
    {
        $this->assertSame('5770.00', LimitMath::remaining('44230.00', '50000.00'));
        $this->assertSame('0.00', LimitMath::remaining('56200.00', '50000.00'));
        $this->assertSame('6200.00', LimitMath::exceededBy('56200.00', '50000.00'));
        $this->assertSame('0.00', LimitMath::exceededBy('44230.00', '50000.00'));
    }

    public function testRatio(): void
    {
        $this->assertSame('0.80000000', LimitMath::ratio('40000.00', '50000.00'));
    }

    public function testFormatPercent(): void
    {
        $this->assertSame('88,5', LimitMath::formatPercent('88.5', 'sk'));
        $this->assertSame('88.5', LimitMath::formatPercent('88.5', 'en'));
        $this->expectException(InvalidArgumentException::class);
        LimitMath::formatPercent('88.50', 'sk');
    }

    public function testIsAlert(): void
    {
        $this->assertFalse($this->makeStatus('normal', null)->isAlert());
        $this->assertTrue($this->makeStatus('warn', null)->isAlert());
        $this->assertTrue($this->makeStatus('normal', 'currency_mismatch')->isAlert());
    }

    private function makeStatus(string $level, ?string $error): LimitStatus
    {
        return new LimitStatus(
            1, 1, 'SK', 'SK', 2026, 2026, null, 'calendar_year', [80, 100],
            '2026-01-01', '2026-12-31', '0.00', 0, 0, '0.00', [2026], '0.00',
            '50000.00', '50000.00', null, false, '0.0', '0.00000000', '50000.00', '0.00',
            'EUR', $level, $error, false,
        );
    }
}
