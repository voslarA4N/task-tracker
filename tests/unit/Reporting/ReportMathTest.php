<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Libraries\Reporting\ReportMath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ReportMathTest extends TestCase
{
    #[DataProvider('providePaidShare')]
    public function testPaidShare(string $cum, string $total, string $tec, string $expected): void
    {
        $this->assertSame($expected, ReportMath::paidShare($cum, $total, $tec));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function providePaidShare(): iterable
    {
        yield 'full' => ['100.00', '100.00', '23.33', '23.33'];

        yield 'partial 1' => ['33.33', '100.00', '23.33', '7.78'];

        yield 'partial 2' => ['66.66', '100.00', '23.33', '15.55'];

        yield 'clamp' => ['150.00', '100.00', '23.33', '23.33'];

        yield 'zero total' => ['10.00', '0.00', '0.00', '0.00'];

        yield 'half up' => ['0.50', '1.00', '0.01', '0.01'];

        yield 'below half' => ['0.49', '1.00', '0.01', '0.00'];

        yield 'same currency' => ['42.50', '100.00', '100.00', '42.50'];
    }
}
