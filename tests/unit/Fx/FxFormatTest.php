<?php

declare(strict_types=1);

namespace Tests\Unit\Fx;

use App\Libraries\Fx\FxFormat;
use App\Libraries\Fx\FxQuote;
use App\Libraries\Fx\RateQuote;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class FxFormatTest extends CIUnitTestCase
{
    public function testRate(): void
    {
        $this->assertSame('4,4090', FxFormat::rate('4.4090', 'sk'));
        $this->assertSame('4.4090', FxFormat::rate('4.4090', 'en'));
        $this->assertSame('24,335', FxFormat::rate('24.335', 'cs'));
        $this->assertSame('4,4090', FxFormat::rate('4.4090', 'pl'));
    }

    /**
     * @return array<string, mixed>
     */
    private function core(string $src, string $cur, string $rate, int $amount, string $dir, string $entity, string $invoice): array
    {
        $q = new RateQuote($src, $cur, $rate, $amount, '2026-10-27', null, $dir);

        return FxQuote::fromRate($q, 'delivery_date', 'previous_business_day', '2026-10-28', $entity, $invoice)->core();
    }

    public function testExpressionNbp(): void
    {
        $this->assertSame('1 EUR = 4,2512 PLN', FxFormat::expression($this->core('NBP', 'EUR', '4.2512', 1, 'multiply', 'PLN', 'EUR'), 'sk'));
    }

    public function testExpressionEcb(): void
    {
        $this->assertSame('1 EUR = 4,3945 PLN', FxFormat::expression($this->core('ECB', 'PLN', '4.3945', 1, 'divide', 'EUR', 'PLN'), 'sk'));
    }

    public function testExpressionCnbAmount(): void
    {
        $this->assertSame('100 HUF = 4,2000 CZK', FxFormat::expression($this->core('CNB', 'HUF', '4.2000', 100, 'multiply', 'CZK', 'HUF'), 'sk'));
    }

    public function testParts(): void
    {
        $this->assertSame(
            ['amount' => 100, 'base' => 'HUF', 'quote' => 'CZK'],
            FxFormat::parts($this->core('CNB', 'HUF', '4.2000', 100, 'multiply', 'CZK', 'HUF')),
        );
        $this->assertSame(
            ['amount' => 1, 'base' => 'EUR', 'quote' => 'PLN'],
            FxFormat::parts($this->core('ECB', 'PLN', '4.3945', 1, 'divide', 'EUR', 'PLN')),
        );
    }

    public function testSourceLabel(): void
    {
        $this->assertSame('ČNB', FxFormat::sourceLabel('CNB'));
        $this->assertSame('NBP', FxFormat::sourceLabel('NBP'));
    }
}
