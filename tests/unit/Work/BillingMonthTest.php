<?php

declare(strict_types=1);

namespace Tests\Unit\Work;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Work\BillingMonth;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class BillingMonthTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testCurrentAndNeighbours(): void
    {
        $this->assertSame('2026-10', BillingMonth::current());
        $this->assertSame('2027-01', BillingMonth::next('2026-12'));
        $this->assertSame('2025-12', BillingMonth::previous('2026-01'));
        $this->assertSame('2028-10', BillingMonth::addMonths('2026-10', 24));
        $this->assertSame('2025-10', BillingMonth::addMonths('2026-10', -12));
    }

    #[DataProvider('provideInRange')]
    public function testInRange(string $month, bool $expected): void
    {
        $this->assertSame($expected, BillingMonth::inRange($month));
        $this->assertSame(['min' => '2025-10', 'max' => '2028-10'], BillingMonth::rangeBounds());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideInRange(): iterable
    {
        yield 'min' => ['2025-10', true];

        yield 'below min' => ['2025-09', false];

        yield 'max' => ['2028-10', true];

        yield 'above max' => ['2028-11', false];
    }

    public function testParse(): void
    {
        $this->assertNull(BillingMonth::parse('2026-1'));
        $this->assertNull(BillingMonth::parse('2026-13'));
        $this->assertNull(BillingMonth::parse("2026-10\n"));
        $this->assertSame('2026-10', BillingMonth::parse('2026-10'));
    }

    public function testLabels(): void
    {
        $this->assertSame('Október 2026', BillingMonth::label('2026-10', 'sk'));
        $this->assertSame('November 2026', BillingMonth::label('2026-11', 'en'));
        $this->assertSame('Okt', substr(BillingMonth::shortLabel('2026-10', 'sk'), 0, 3));
        $this->assertStringNotContainsString('.', BillingMonth::shortLabel('2026-10', 'sk'));
    }
}
