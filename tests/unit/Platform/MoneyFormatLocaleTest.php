<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Reporting\LimitMath;
use App\Libraries\Work\BillingMonth;
use CodeIgniter\Test\CIUnitTestCase;
use NumberFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tabulka formatov UI-SPEC C pre sk, pl, cs, en. Zdrojom pravdy je ICU (INTL_ICU_VERSION v kontajneri php).
 *
 * @internal
 */
#[Group('unit')]
final class MoneyFormatLocaleTest extends CIUnitTestCase
{
    private static function normalize(string $s): string
    {
        return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $s);
    }

    #[DataProvider('provideMoney')]
    public function testMoney(string $locale, string $currency, string $amount, string $expected): void
    {
        $this->assertSame($expected, self::normalize(MoneyFormat::format($amount, $currency, $locale)));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideMoney(): iterable
    {
        yield 'sk EUR' => ['sk', 'EUR', '1080.00', '1 080,00 €'];

        yield 'pl EUR' => ['pl', 'EUR', '1080.00', '1 080,00 €'];

        yield 'cs EUR' => ['cs', 'EUR', '1080.00', '1 080,00 €'];

        yield 'en EUR' => ['en', 'EUR', '1080.00', '€1,080.00'];

        yield 'pl PLN' => ['pl', 'PLN', '1080.00', '1 080,00 zł'];

        yield 'cs CZK' => ['cs', 'CZK', '1080.00', '1 080,00 Kč'];

        yield 'pl zero' => ['pl', 'EUR', '0.00', '0,00 €'];
    }

    public function testSlovakPlnUsesIcuSymbolAfterNumber(): void
    {
        $symbol = MoneyFormat::symbol('PLN', 'sk');

        $this->assertSame('1 080,00 ' . $symbol, self::normalize(MoneyFormat::format('1080.00', 'PLN', 'sk')));
    }

    public function testEnglishCzkUsesIcuSymbolBeforeNumber(): void
    {
        $symbol = MoneyFormat::symbol('CZK', 'en');

        $this->assertStringStartsWith($symbol, self::normalize(MoneyFormat::format('1080.00', 'CZK', 'en')));
        $this->assertStringEndsWith('1,080.00', self::normalize(MoneyFormat::format('1080.00', 'CZK', 'en')));
    }

    public function testNegativeSlovak(): void
    {
        $out = MoneyFormat::format('-1080.50', 'EUR', 'sk');

        $this->assertStringStartsWith('-', $out);
        $this->assertStringEndsWith('1 080,50 €', self::normalize($out));
    }

    #[DataProvider('provideNonBreakingSpaceBeforeSymbol')]
    public function testNonBreakingSpaceBeforeSymbol(string $locale): void
    {
        $this->assertStringContainsString("\u{00A0}", MoneyFormat::format('1080.00', 'EUR', $locale));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonBreakingSpaceBeforeSymbol(): iterable
    {
        yield 'sk' => ['sk'];

        yield 'pl' => ['pl'];

        yield 'cs' => ['cs'];
    }

    public function testIcuDataForCzechPresent(): void
    {
        $formatted = (new NumberFormatter('cs', NumberFormatter::CURRENCY))->formatCurrency(1, 'CZK');

        $this->assertStringContainsString('Kč', (string) $formatted, 'Chybaju ICU data pre cs v obraze (ICU ' . INTL_ICU_VERSION . ').');
    }

    #[DataProvider('providePercentAndHours')]
    public function testPercentAndHours(string $locale, string $sep): void
    {
        $this->assertSame('85' . $sep . '5', LimitMath::formatPercent('85.5', $locale));
        $this->assertSame('7' . $sep . '5', Hours::format('7.50', $locale));
        $this->assertSame('+1' . $sep . '25', Hours::formatSigned('1.25', $locale));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providePercentAndHours(): iterable
    {
        yield 'sk' => ['sk', ','];

        yield 'pl' => ['pl', ','];

        yield 'cs' => ['cs', ','];

        yield 'en' => ['en', '.'];
    }

    #[DataProvider('provideMonthLabel')]
    public function testMonthLabel(string $month, string $locale, string $expected): void
    {
        $this->assertSame($expected, self::normalize(BillingMonth::label($month, $locale)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideMonthLabel(): iterable
    {
        yield 'sk' => ['2026-11', 'sk', 'November 2026'];

        yield 'pl' => ['2026-11', 'pl', 'Listopad 2026'];

        yield 'cs' => ['2026-11', 'cs', 'Listopad 2026'];

        yield 'en' => ['2026-11', 'en', 'November 2026'];

        yield 'pl nominative' => ['2026-10', 'pl', 'Październik 2026'];
    }
}
