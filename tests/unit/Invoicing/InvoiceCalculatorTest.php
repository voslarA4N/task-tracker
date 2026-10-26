<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\InvoiceCalculator;
use App\Libraries\Invoicing\IssueRejected;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class InvoiceCalculatorTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function part(int $id, string $hours, string $rate, string $amount, string $currency = 'EUR'): array
    {
        return [
            'allocation_id'   => $id,
            'task_id'         => $id + 100,
            'title'           => 'Task ' . $id,
            'task_ref'        => 'T-' . $id,
            'portal_url'      => 'https://portal.example/' . $id,
            'estimated_hours' => '50.00',
            'hours'           => $hours,
            'rate'            => $rate,
            'amount'          => $amount,
            'currency'        => $currency,
        ];
    }

    public function testSinglePart(): void
    {
        $r = (new InvoiceCalculator())->compute([$this->part(1, '40.00', '27.00', '1080.00')], 'np');

        $this->assertSame('1080.00', $r['total']);
        $this->assertSame('1080.00', $r['lines'][0]['amount']);
        $this->assertSame('40.00', $r['hours']);
        $this->assertSame(1, $r['line_count']);
        $this->assertSame(1, $r['lines'][0]['position']);
        $this->assertSame('np', $r['lines'][0]['vat_code']);
        $this->assertSame('EUR', $r['currency']);
        $this->assertSame(1, $r['lines'][0]['source_allocation_id']);
        $this->assertSame(101, $r['lines'][0]['source_task_id']);
        $this->assertSame('T-1', $r['lines'][0]['task_ref']);
        $this->assertSame('https://portal.example/1', $r['lines'][0]['portal_url']);
        $this->assertSame('50.00', $r['lines'][0]['estimated_hours']);
    }

    public function testHalfUpRounding(): void
    {
        $calc = new InvoiceCalculator();

        $this->assertSame('229.08', $calc->compute([$this->part(1, '8.33', '27.50', '229.08')], null)['total']);
        $this->assertSame('0.01', $calc->compute([$this->part(1, '0.01', '0.50', '0.01')], null)['total']);
        $this->assertNull($calc->compute([$this->part(1, '0.01', '0.50', '0.01')], null)['lines'][0]['vat_code']);
    }

    public function testTotalIsSumOfRoundedLines(): void
    {
        $r = (new InvoiceCalculator())->compute([
            $this->part(1, '1.11', '33.33', '37.00'),
            $this->part(2, '2.22', '33.33', '73.99'),
            $this->part(3, '3.33', '33.33', '110.99'),
        ], 'np');

        $this->assertSame('221.98', $r['total']);
        $this->assertSame('6.66', $r['hours']);
        $this->assertSame(3, $r['line_count']);
        $this->assertSame(3, $r['lines'][2]['position']);
    }

    public function testAmountMismatchRejected(): void
    {
        try {
            (new InvoiceCalculator())->compute([$this->part(7, '40.00', '27.00', '1079.99')], 'np');
            $this->fail('expected rejection');
        } catch (IssueRejected $e) {
            $this->assertSame('amount_mismatch', $e->reason);
            $this->assertSame(7, $e->context['allocation_id']);
        }
    }

    public function testMixedCurrencyRejected(): void
    {
        try {
            (new InvoiceCalculator())->compute([
                $this->part(1, '1.00', '10.00', '10.00', 'EUR'),
                $this->part(2, '1.00', '10.00', '10.00', 'PLN'),
            ], 'np');
            $this->fail('expected rejection');
        } catch (IssueRejected $e) {
            $this->assertSame('mixed_currency', $e->reason);
            $this->assertSame(['EUR', 'PLN'], $e->context['currencies']);
        }
    }

    public function testNoPartsRejected(): void
    {
        $this->expectException(IssueRejected::class);
        $this->expectExceptionMessage('no_parts');
        (new InvoiceCalculator())->compute([], 'np');
    }

    public function testPreviewToken(): void
    {
        $a = (new InvoiceCalculator())->compute([
            $this->part(1, '1.00', '10.00', '10.00'),
            $this->part(2, '2.00', '10.00', '20.00'),
        ], 'np')['lines'];
        $reversed = array_reverse($a);

        $base = InvoiceCalculator::previewToken(1, '2026-10', 2, $a);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $base);
        $this->assertSame($base, InvoiceCalculator::previewToken(1, '2026-10', 2, $reversed));
        $this->assertNotSame($base, InvoiceCalculator::previewToken(9, '2026-10', 2, $a));
        $this->assertNotSame($base, InvoiceCalculator::previewToken(1, '2026-11', 2, $a));
        $this->assertNotSame($base, InvoiceCalculator::previewToken(1, '2026-10', 3, $a));

        $changed             = $a;
        $changed[0]['hours'] = '1.50';
        $this->assertNotSame($base, InvoiceCalculator::previewToken(1, '2026-10', 2, $changed));

        $changed            = $a;
        $changed[0]['rate'] = '11.00';
        $this->assertNotSame($base, InvoiceCalculator::previewToken(1, '2026-10', 2, $changed));
    }

    public function testNoFloatsInOutput(): void
    {
        $r = (new InvoiceCalculator())->compute([$this->part(1, '8.33', '27.50', '229.08')], 'np');

        $this->assertIsString($r['lines'][0]['hours']);
        $this->assertIsString($r['lines'][0]['rate']);
        $this->assertIsString($r['lines'][0]['amount']);
    }
}
