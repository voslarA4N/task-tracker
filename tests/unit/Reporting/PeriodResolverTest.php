<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Libraries\Reporting\PeriodResolver;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class PeriodResolverTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{string, string, ?string, string, string, list<int>, ?int, bool}>
     */
    public static function periods(): iterable
    {
        yield 'cal start' => ['calendar_year', '2026-01-01', null, '2026-01-01', '2026-12-31', [2026], null, false];

        yield 'cal end' => ['calendar_year', '2026-12-31', null, '2026-01-01', '2026-12-31', [2026], null, false];

        yield 'rolling nov' => ['rolling_12m', '2026-11-15', null, '2025-12-01', '2026-11-30', [2025, 2026], null, false];

        yield 'rolling dec' => ['rolling_12m', '2026-12-31', null, '2026-01-01', '2026-12-31', [2026], null, false];

        yield 'rolling 31 mar' => ['rolling_12m', '2026-03-31', null, '2025-04-01', '2026-03-31', [2025, 2026], null, false];

        yield 'rolling leap' => ['rolling_12m', '2028-02-29', null, '2027-03-01', '2028-02-29', [2027, 2028], null, false];

        yield 'rolling jan' => ['rolling_12m', '2026-01-10', null, '2025-02-01', '2026-01-31', [2025, 2026], null, false];

        yield 'prorata first' => ['pro_rata_first_year', '2026-10-01', '2026-07-15', '2026-07-15', '2026-12-31', [2026], 170, false];

        yield 'prorata later' => ['pro_rata_first_year', '2027-03-01', '2026-07-15', '2027-01-01', '2027-12-31', [2027], null, true];

        yield 'prorata cap' => ['pro_rata_first_year', '2028-05-01', '2028-01-01', '2028-01-01', '2028-12-31', [2028], 365, false];

        yield 'prorata one day' => ['pro_rata_first_year', '2026-12-31', '2026-12-31', '2026-12-31', '2026-12-31', [2026], 1, false];

        yield 'prorata null' => ['pro_rata_first_year', '2026-05-01', null, '2026-01-01', '2026-12-31', [2026], null, true];
    }

    /**
     * @param list<int> $years
     */
    #[DataProvider('periods')]
    public function testResolve(string $method, string $ref, ?string $activeFrom, string $from, string $to, array $years, ?int $days, bool $outside): void
    {
        $p = PeriodResolver::resolve($method, $ref, $activeFrom);
        $this->assertSame($from, $p->from);
        $this->assertSame($to, $p->to);
        $this->assertSame($years, $p->openingYears);
        $this->assertSame($days, $p->proRataDays);
        $this->assertSame($outside, $p->proRataOutsideFirstYear);
    }

    public function testProRataLimit(): void
    {
        $this->assertSame('111780.82', PeriodResolver::proRataLimit('240000.00', 170));
        $this->assertSame('240000.00', PeriodResolver::proRataLimit('240000.00', 365));
        $this->assertSame('136.99', PeriodResolver::proRataLimit('50000.00', 1));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'method' => ['monthly', '2026-05-01'];

        yield 'feb 30' => ['calendar_year', '2026-02-30'];

        yield 'format' => ['calendar_year', '31.12.2026'];
    }

    #[DataProvider('invalid')]
    public function testInvalidInput(string $method, string $ref): void
    {
        $this->expectException(InvalidArgumentException::class);
        PeriodResolver::resolve($method, $ref, null);
    }

    public function testInvalidActiveFrom(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PeriodResolver::resolve('pro_rata_first_year', '2026-05-01', '2026-02-30');
    }

    public function testProRataDaysTooLow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PeriodResolver::proRataLimit('1000.00', 0);
    }

    public function testProRataDaysTooHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PeriodResolver::proRataLimit('1000.00', 366);
    }
}
