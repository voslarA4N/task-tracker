<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Libraries\Platform\MoneyFormat;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class MoneyFormatTest extends CIUnitTestCase
{
    public function testSlovakEuro(): void
    {
        $out = MoneyFormat::format('1080.00', 'EUR', 'sk');
        $this->assertStringEndsWith("\u{00A0}€", $out);
        $this->assertStringContainsString(',00', $out);
        $this->assertSame('1080,00', preg_replace('/[\s\x{00A0}\x{202F}]/u', '', substr($out, 0, -strlen("\u{00A0}€"))));
    }

    public function testPolishZloty(): void
    {
        $this->assertStringContainsString('zł', MoneyFormat::format('27.00', 'PLN', 'sk'));
    }

    public function testNegativeStartsWithMinus(): void
    {
        $out = MoneyFormat::format('-27.50', 'EUR', 'sk');
        $this->assertMatchesRegularExpression('/^[-\x{2212}]/u', $out);
        $this->assertStringContainsString('27,50', $out);
    }

    public function testNoFloatPrecisionLoss(): void
    {
        $out = MoneyFormat::format('99999999.99', 'EUR', 'sk');
        $this->assertSame('9999999999', preg_replace('/\D/', '', $out));
    }

    public function testPrefixLocale(): void
    {
        $this->assertSame('€1,080.00', MoneyFormat::format('1080.00', 'EUR', 'en'));
    }
}
