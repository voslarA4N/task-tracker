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

    /**
     * @return array<string, mixed>
     */
    private function snap(): array
    {
        return [
            'number'          => null,
            'variable_symbol' => null,
            'issue_date'      => '2026-10-27',
            'delivery_date'   => '2026-10-31',
            'due_date'        => '2026-11-10',
            'entity'          => ['version_id' => 1, 'address_line1' => 'A 1'],
            'bank'            => ['iban' => 'SK3112000000198742637541'],
            'legal'           => ['vat_code' => 'np', 'texts' => ['vat_note' => 'x']],
            'client'          => ['address_line1' => 'C 1'],
            'lines'           => [
                ['source_allocation_id' => 1, 'hours' => '1.00', 'rate' => '10.00', 'amount' => '10.00'],
                ['source_allocation_id' => 2, 'hours' => '2.00', 'rate' => '10.00', 'amount' => '20.00'],
            ],
        ];
    }

    public function testPreviewToken(): void
    {
        $s    = $this->snap();
        $base = InvoiceCalculator::previewToken($s);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $base);

        $n                    = $s;
        $n['number']          = 'FA-1';
        $n['variable_symbol'] = '1';
        $this->assertSame($base, InvoiceCalculator::previewToken($n));

        $mutations = [
            static function (array $x): array { $x['issue_date'] = '2026-10-28'; return $x; },
            static function (array $x): array { $x['delivery_date'] = '2026-10-30'; return $x; },
            static function (array $x): array { $x['due_date'] = '2026-11-11'; return $x; },
            static function (array $x): array { $x['entity']['version_id'] = 2; return $x; },
            static function (array $x): array { $x['entity']['address_line1'] = 'B 2'; return $x; },
            static function (array $x): array { $x['bank']['iban'] = 'PL61109010140000071219812874'; return $x; },
            static function (array $x): array { $x['legal']['vat_code'] = 'sk'; return $x; },
            static function (array $x): array { $x['legal']['texts']['vat_note'] = 'y'; return $x; },
            static function (array $x): array { $x['client']['address_line1'] = 'D 4'; return $x; },
            static function (array $x): array { $x['lines'][0]['hours'] = '1.50'; return $x; },
            static function (array $x): array { $x['lines'][0]['rate'] = '11.00'; return $x; },
            static function (array $x): array { $x['lines'] = array_reverse($x['lines']); return $x; },
        ];

        foreach ($mutations as $i => $m) {
            $this->assertNotSame($base, InvoiceCalculator::previewToken($m($s)), 'mutation ' . $i);
        }
    }

    public function testNoFloatsInOutput(): void
    {
        $r = (new InvoiceCalculator())->compute([$this->part(1, '8.33', '27.50', '229.08')], 'np');

        $this->assertIsString($r['lines'][0]['hours']);
        $this->assertIsString($r['lines'][0]['rate']);
        $this->assertIsString($r['lines'][0]['amount']);
    }
}
