<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Libraries\Reporting\ReportPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ReportPeriodTest extends TestCase
{
    public function testMonth(): void
    {
        $p = ReportPeriod::month('2026-10');
        $this->assertSame('month', $p->kind);
        $this->assertSame('2026-10-01', $p->from);
        $this->assertSame('2026-11-01', $p->until);
        $this->assertSame('2026-10', $p->key);
        $this->assertSame('2026-10-31', $p->lastDay());
        $this->assertSame(['2026-10'], $p->months());
    }

    public function testLeapYearLastDay(): void
    {
        $this->assertSame('2024-02-29', ReportPeriod::month('2024-02')->lastDay());
        $this->assertSame('2026-02-28', ReportPeriod::month('2026-02')->lastDay());
        $this->assertSame('2100-02-28', ReportPeriod::month('2100-02')->lastDay());
        $this->assertSame('2000-02-29', ReportPeriod::month('2000-02')->lastDay());
    }

    public function testYear(): void
    {
        $p = ReportPeriod::year(2026);
        $this->assertSame('year', $p->kind);
        $this->assertSame('2026-01-01', $p->from);
        $this->assertSame('2027-01-01', $p->until);
        $this->assertSame('2026', $p->key);
        $this->assertCount(12, $p->months());
        $this->assertSame('2026-12-31', $p->lastDay());
    }

    public function testRange(): void
    {
        $p = ReportPeriod::range('2026-01', '2026-03');
        $this->assertSame('range', $p->kind);
        $this->assertSame('2026-01-01', $p->from);
        $this->assertSame('2026-04-01', $p->until);
        $this->assertSame('2026-01_2026-03', $p->key);
        $this->assertSame(['2026-01', '2026-02', '2026-03'], $p->months());
    }

    public function testRangeOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('order');
        ReportPeriod::range('2026-03', '2026-01');
    }

    public function testRangeTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too_long');
        ReportPeriod::range('2025-01', '2026-01');
    }

    public function testRangeOfTwelveMonthsIsAllowed(): void
    {
        $this->assertCount(12, ReportPeriod::range('2025-02', '2026-01')->months());
    }

    public function testInvalidMonth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::month('2026-13');
    }

    public function testInvalidYear(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::year(1999);
    }

    public function testPreviousNext(): void
    {
        $this->assertSame('2025-12', ReportPeriod::month('2026-01')->previous()->key);
        $this->assertSame('2027-01', ReportPeriod::month('2026-12')->next()->key);
        $this->assertSame('2025', ReportPeriod::year(2026)->previous()->key);
        $this->assertSame('2027', ReportPeriod::year(2026)->next()->key);
    }

    public function testIsAfterMonth(): void
    {
        $this->assertTrue(ReportPeriod::month('2026-12')->isAfterMonth('2026-11'));
        $this->assertFalse(ReportPeriod::month('2026-11')->isAfterMonth('2026-11'));
        $this->assertFalse(ReportPeriod::month('2026-10')->isAfterMonth('2026-11'));
    }
}
