<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Libraries\Platform\Money;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use TypeError;

/**
 * @internal
 */
final class MoneyTest extends CIUnitTestCase
{
    public function testBasicMultiplication(): void
    {
        $this->assertSame('33.75', Money::of('27.00')->timesHours('1.25')->toDecimalString());
    }

    #[DataProvider('provideHalfUpRounding')]
    public function testHalfUpRounding(string $rate, string $hours, string $expected): void
    {
        $this->assertSame($expected, Money::of($rate)->timesHours($hours)->toDecimalString());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideHalfUpRounding(): iterable
    {
        return [
            'tie +0.125' => ['0.25', '0.50', '0.13'],
            'tie +1.005' => ['2.01', '0.50', '1.01'],
            'tie +0.005' => ['0.01', '0.50', '0.01'],
            'tie -0.125' => ['-0.25', '0.50', '-0.13'],
            'tie -1.005' => ['-2.01', '0.50', '-1.01'],
            'tie -0.005' => ['-0.01', '0.50', '-0.01'],
            'neg zero'   => ['-0.01', '0.40', '0.00'],
            'below tie'  => ['0.01', '0.40', '0.00'],
            'exact'      => ['10.00', '0.25', '2.50'],
        ];
    }

    public function testNegativeZeroNormalization(): void
    {
        $this->assertSame('0.00', Money::of('-0')->toDecimalString());
        $this->assertSame('0.00', Money::of('-0.00')->toDecimalString());
        $this->assertSame('0.00', Money::of('0.00')->negate()->toDecimalString());
        $this->assertSame('0.00', (string) Money::of('-0.01')->plus(Money::of('0.01')));
    }

    public function testInputNormalization(): void
    {
        $this->assertSame('5.00', Money::of('5')->toDecimalString());
        $this->assertSame('5.50', Money::of('5.5')->toDecimalString());
        $this->assertSame('0.00', Money::zero()->toDecimalString());
    }

    public function testSumIsSumOfRoundedLines(): void
    {
        $line = Money::of('0.25')->timesHours('0.50');
        $this->assertSame('0.39', Money::sum($line, $line, $line)->toDecimalString());
        $this->assertSame('0.00', Money::sum()->toDecimalString());
    }

    public function testArithmetic(): void
    {
        $a = Money::of('10.10');
        $b = Money::of('0.20');
        $this->assertSame('10.30', $a->plus($b)->toDecimalString());
        $this->assertSame('9.90', $a->minus($b)->toDecimalString());
        $this->assertSame('-10.10', $a->negate()->toDecimalString());
        $this->assertSame(1, $a->compare($b));
        $this->assertSame(-1, $b->compare($a));
        $this->assertFalse($a->equals($b));
        $this->assertTrue($a->equals(Money::of('10.1')));
        $this->assertFalse($a->isZero());
        $this->assertTrue(Money::zero()->isZero());
    }

    #[DataProvider('provideInvalidMoneyRejected')]
    public function testInvalidMoneyRejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of($value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidMoneyRejected(): iterable
    {
        return [
            'comma'       => ['1,5'],
            'three dec'   => ['1.234'],
            'empty'       => [''],
            'leading ws'  => [' 1'],
            'trailing ws' => ['1 '],
            'trailing nl' => ["1\n"],
            'letters'     => ['abc'],
            'exponent'    => ['1e3'],
            'plus sign'   => ['+1'],
            'too long'    => ['12345678901'],
        ];
    }

    #[DataProvider('provideInvalidHoursRejected')]
    public function testInvalidHoursRejected(string $hours): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1.00')->timesHours($hours);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidHoursRejected(): iterable
    {
        return [
            'three dec' => ['1.234'],
            'too long'  => ['123456'],
            'comma'     => ['1,5'],
            'empty'     => [''],
        ];
    }

    public function testOverflowOnMultiplication(): void
    {
        $this->expectException(OverflowException::class);
        Money::of('9999999999.99')->timesHours('2.00');
    }

    public function testOverflowOnAddition(): void
    {
        $this->expectException(OverflowException::class);
        Money::of('9999999999.99')->plus(Money::of('0.01'));
    }

    public function testFloatInputIsTypeError(): void
    {
        $this->expectException(TypeError::class);
        Money::of(1.5); // @phpstan-ignore argument.type
    }

    public function testResultAlwaysHasTwoDecimals(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $rate  = sprintf('%d.%02d', mt_rand(0, 9999), mt_rand(0, 99));
            $hours = sprintf('%d.%02d', mt_rand(0, 999), mt_rand(0, 99));
            if (mt_rand(0, 1) === 1) {
                $rate = '-' . $rate;
            }
            $result = Money::of($rate)->timesHours($hours)->toDecimalString();
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $result);
            $this->assertNotSame('-0.00', $result);
        }
    }
}
