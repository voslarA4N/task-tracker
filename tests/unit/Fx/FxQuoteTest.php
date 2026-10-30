<?php

declare(strict_types=1);

namespace Tests\Unit\Fx;

use App\Libraries\Fx\FxQuote;
use App\Libraries\Fx\RateQuote;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * @internal
 */
final class FxQuoteTest extends CIUnitTestCase
{
    private function nbp(): RateQuote
    {
        return new RateQuote('NBP', 'EUR', '4.4090', 1, '2026-10-27', '209/A/NBP/2026', 'multiply');
    }

    public function testFromRateCore(): void
    {
        $core = FxQuote::fromRate($this->nbp(), 'delivery_date', 'previous_business_day', '2026-10-28', 'PLN', 'EUR')->core();

        $this->assertSame([
            'source', 'manual', 'rate', 'rate_display', 'amount', 'direction', 'valid_for', 'table_no',
            'date_basis', 'day_rule', 'anchor_date', 'entity_currency', 'invoice_currency', 'fetched',
        ], array_keys($core));
        $this->assertSame('4.40900000', $core['rate']);
        $this->assertSame('4.4090', $core['rate_display']);
        $this->assertFalse($core['manual']);
        $this->assertNull($core['fetched']);
        $this->assertSame('NBP', $core['source']);
        $this->assertSame('209/A/NBP/2026', $core['table_no']);
    }

    public function testManualCoreKeepsFetched(): void
    {
        $core = FxQuote::manual('4.3000', '2026-10-28', 'multiply', 'delivery_date', 'previous_business_day', '2026-10-28', 'PLN', 'EUR', $this->nbp())->core();

        $this->assertSame('manual', $core['source']);
        $this->assertTrue($core['manual']);
        $this->assertNull($core['table_no']);
        $this->assertSame('4.3000', $core['rate_display']);
        $this->assertSame([
            'source'   => 'NBP',
            'rate'     => '4.4090',
            'amount'   => 1,
            'valid_for' => '2026-10-27',
            'table_no' => '209/A/NBP/2026',
        ], $core['fetched']);
    }

    public function testManualWithoutFetched(): void
    {
        $core = FxQuote::manual('4.3000', '2026-10-28', 'multiply', 'delivery_date', 'previous_business_day', '2026-10-28', 'PLN', 'EUR', null)->core();
        $this->assertNull($core['fetched']);
    }

    public function testDeterministic(): void
    {
        $q = FxQuote::fromRate($this->nbp(), 'delivery_date', 'previous_business_day', '2026-10-28', 'PLN', 'EUR');
        $this->assertSame(json_encode($q->core()), json_encode($q->core()));
    }

    public function testToRateQuote(): void
    {
        $nbp = $this->nbp();
        $this->assertEquals($nbp, FxQuote::fromRate($nbp, 'delivery_date', 'previous_business_day', '2026-10-28', 'PLN', 'EUR')->toRateQuote());
        $this->assertNull(FxQuote::manual('4.3', '2026-10-28', 'multiply', 'delivery_date', 'previous_business_day', '2026-10-28', 'PLN', 'EUR', $nbp)->toRateQuote());
    }

    public function testManualRejectsBadInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxQuote::manual('4.3', '2026-10-28', 'x', 'delivery_date', 'previous_business_day', '2026-10-28', 'PLN', 'EUR', null);
    }

    public function testManualRejectsZeroRate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxQuote::manual('0', '2026-10-28', 'multiply', 'delivery_date', 'previous_business_day', '2026-10-28', 'PLN', 'EUR', null);
    }
}
