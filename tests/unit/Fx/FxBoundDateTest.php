<?php

declare(strict_types=1);

namespace Tests\Unit\Fx;

use App\Libraries\Fx\FxBoundDate;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * @internal
 */
final class FxBoundDateTest extends CIUnitTestCase
{
    public function testAnchor(): void
    {
        $this->assertSame('2026-10-31', FxBoundDate::anchor('delivery_date', '2026-10-27', '2026-10-31'));
        $this->assertSame('2026-10-27', FxBoundDate::anchor('issue_date', '2026-10-27', '2026-10-31'));
    }

    public function testBound(): void
    {
        $this->assertSame('2026-11-01', FxBoundDate::bound('previous_business_day', '2026-11-02'));
        $this->assertSame('2026-11-02', FxBoundDate::bound('on_or_before', '2026-11-02'));
        $this->assertSame('2025-12-31', FxBoundDate::bound('previous_business_day', '2026-01-01'));
    }

    public function testUnknownBasis(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxBoundDate::anchor('x', '2026-10-27', '2026-10-31');
    }

    public function testUnknownRule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxBoundDate::bound('x', '2026-10-27');
    }
}
