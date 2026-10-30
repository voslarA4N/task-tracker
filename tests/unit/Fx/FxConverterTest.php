<?php

declare(strict_types=1);

namespace Tests\Unit\Fx;

use App\Libraries\Fx\FxConverter;
use App\Libraries\Fx\RateQuote;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class FxConverterTest extends CIUnitTestCase
{
    #[DataProvider('provideConvert')]
    public function testConvert(string $total, string $rate, int $amount, string $direction, string $expected): void
    {
        $this->assertSame($expected, FxConverter::convert($total, $rate, $amount, $direction));
    }

    /**
     * @return iterable<string, array{string, string, int, string, string}>
     */
    public static function provideConvert(): iterable
    {
        yield 'EUR to PLN NBP' => ['1000.00', '4.4090', 1, 'multiply', '4409.00'];
        yield 'EUR to PLN 4.2512' => ['1000.00', '4.2512', 1, 'multiply', '4251.20'];
        yield 'PLN to EUR ECB' => ['4394.50', '4.3945', 1, 'divide', '1000.00'];
        yield 'PLN to EUR ECB rounding' => ['4409.00', '4.3938', 1, 'divide', '1003.46'];
        yield 'EUR to CZK' => ['1000.00', '24.335', 1, 'multiply', '24335.00'];
        yield 'CZK to EUR ECB' => ['24335.00', '24.369', 1, 'divide', '998.60'];
        yield 'PLN to CZK' => ['1000.00', '5.758', 1, 'multiply', '5758.00'];
        yield 'CNB amount 100' => ['100.00', '4.2000', 100, 'multiply', '4.20'];
        yield 'half up boundary' => ['0.01', '0.5', 1, 'multiply', '0.01'];
        yield 'below boundary' => ['0.01', '0.4999', 1, 'multiply', '0.00'];
        yield 'negative symmetric' => ['-1000.00', '4.2512', 1, 'multiply', '-4251.20'];
        yield 'zero' => ['0.00', '4.2512', 1, 'multiply', '0.00'];
    }

    public function testOverflow(): void
    {
        $this->expectException(OverflowException::class);
        FxConverter::convert('9999999999.99', '24.335', 1, 'multiply');
    }

    #[DataProvider('provideInvalid')]
    public function testInvalidInput(string $total, string $rate, int $amount, string $direction): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxConverter::convert($total, $rate, $amount, $direction);
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'zero rate' => ['1000.00', '0', 1, 'multiply'];
        yield 'negative rate' => ['1000.00', '-1', 1, 'multiply'];
        yield 'comma rate' => ['1000.00', '4,25', 1, 'multiply'];
        yield 'exponent rate' => ['1000.00', '1e3', 1, 'multiply'];
        yield 'nine decimals' => ['1000.00', '4.123456789', 1, 'multiply'];
        yield 'amount zero' => ['1000.00', '4.25', 0, 'multiply'];
        yield 'bad direction' => ['1000.00', '4.25', 1, 'x'];
        yield 'bad total' => ['10.001', '4.25', 1, 'multiply'];
    }

    #[DataProvider('provideBadQuote')]
    public function testRateQuoteRejects(string $currency, string $rate, int $amount, string $direction): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RateQuote('NBP', $currency, $rate, $amount, '2026-10-27', null, $direction);
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function provideBadQuote(): iterable
    {
        yield 'zero' => ['EUR', '0', 1, 'multiply'];
        yield 'negative' => ['EUR', '-1', 1, 'multiply'];
        yield 'comma' => ['EUR', '4,25', 1, 'multiply'];
        yield 'exp' => ['EUR', '1e3', 1, 'multiply'];
        yield 'nine decimals' => ['EUR', '4.123456789', 1, 'multiply'];
        yield 'amount zero' => ['EUR', '4.25', 0, 'multiply'];
        yield 'direction' => ['EUR', '4.25', 1, 'x'];
        yield 'currency' => ['eur', '4.25', 1, 'multiply'];
    }

    public function testRateQuoteKeepsString(): void
    {
        $q = new RateQuote('NBP', 'EUR', '4.4090', 1, '2026-10-27', '209/A/NBP/2026', 'multiply');
        $this->assertSame('4.4090', $q->rate);
    }
}
