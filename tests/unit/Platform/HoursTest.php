<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Libraries\Platform\Hours;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class HoursTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideOf(): iterable
    {
        yield 'comma' => ['8,5', '8.50'];

        yield 'trim' => [' 40 ', '40.00'];

        yield 'space thousands' => ['1 000', '1000.00'];

        yield 'nbsp thousands' => ["1\u{00A0}000", '1000.00'];

        yield 'zero' => ['0', '0.00'];
    }

    #[DataProvider('provideOf')]
    public function testOf(string $raw, string $expected): void
    {
        $this->assertSame($expected, Hours::of($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'three decimals' => ['8.555'];

        yield 'negative' => ['-1'];

        yield 'letters' => ['abc'];

        yield 'empty' => [''];

        yield 'too long' => ['123456'];
    }

    #[DataProvider('provideInvalid')]
    public function testOfRejects(string $raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        Hours::of($raw);
    }

    public function testArithmetic(): void
    {
        $this->assertSame('24.00', Hours::plus('16.00', '8.00'));
        $this->assertSame('8.00', Hours::minus('16.00', '8.00'));
        $this->assertSame(-1, Hours::compare('1.00', '2.00'));
        $this->assertTrue(Hours::isPositive('0.01'));
        $this->assertFalse(Hours::isPositive('0.00'));
    }

    public function testRemainingNeverBelowZero(): void
    {
        $this->assertSame('16.00', Hours::remaining('40.00', '24.00'));
        $this->assertSame('0.00', Hours::remaining('40.00', '46.00'));
        $this->assertSame('0.00', Hours::remaining('40.00', '40.00'));
        $this->assertSame('0.00', Hours::remaining('0.00', '0.00'));
    }

    public function testDelta(): void
    {
        $this->assertSame('6.00', Hours::delta('46.00', '40.00'));
        $this->assertSame('-2.00', Hours::delta('38.00', '40.00'));
        $this->assertSame('0.00', Hours::delta('40.00', '40.00'));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function providePercent(): iterable
    {
        yield 'forty' => ['16.00', '40.00', 40];

        yield 'third' => ['1.00', '3.00', 35];

        yield 'over' => ['50.00', '40.00', 100];

        yield 'zero total' => ['1.00', '0.00', 0];

        yield 'zero part' => ['0.00', '40.00', 0];
    }

    #[DataProvider('providePercent')]
    public function testPercentOf(string $part, string $total, int $expected): void
    {
        $this->assertSame($expected, Hours::percentOf($part, $total));
    }

    public function testFormat(): void
    {
        $this->assertSame('40', Hours::format('40.00', 'sk'));
        $this->assertSame('8,5', Hours::format('8.50', 'sk'));
        $this->assertSame('8.25', Hours::format('8.25', 'en'));
        $this->assertSame('8,25', Hours::format('8.25', 'pl'));
    }

    public function testFormatSigned(): void
    {
        $this->assertSame('+6', Hours::formatSigned('6.00', 'sk'));
        $this->assertSame("\u{2212}2,5", Hours::formatSigned('-2.50', 'sk'));
        $this->assertSame('0', Hours::formatSigned('0.00', 'sk'));
    }
}
