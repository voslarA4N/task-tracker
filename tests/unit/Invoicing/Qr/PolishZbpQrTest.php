<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing\Qr;

use App\Libraries\Invoicing\Qr\PolishZbpQr;
use App\Libraries\Invoicing\Qr\QrPaymentFailed;
use App\Libraries\Invoicing\Samples\SampleSnapshots;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class PolishZbpQrTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function snap(): array
    {
        $s                         = SampleSnapshots::invoice('pl');
        $s['entity']['tax_id']     = '1234563218';
        $s['entity']['trade_name'] = 'Jan Kowalski Usługi Informatyczne';

        return $s;
    }

    public function testReferenceVector(): void
    {
        $this->assertSame(
            '1234563218|PL|61109010140000071219812874|130950|Jan Kowalski Usługi|202610001|||',
            (new PolishZbpQr())->encode($this->snap()),
        );
    }

    public function testAmountPaddingNipAndPipeStripping(): void
    {
        $s                         = $this->snap();
        $s['totals']['total']      = '5.00';
        $s['entity']['tax_id']     = 'PL 123-456-32-18';
        $s['entity']['trade_name'] = 'A|B';
        $out                       = (new PolishZbpQr())->encode($s);

        $this->assertStringStartsWith('1234563218|PL|61109010140000071219812874|000500|AB|', $out);
    }

    public function testMissingNipFails(): void
    {
        $s                     = $this->snap();
        $s['entity']['tax_id'] = null;

        try {
            (new PolishZbpQr())->encode($s);
            $this->fail('Expected QrPaymentFailed');
        } catch (QrPaymentFailed $e) {
            $this->assertSame(QrPaymentFailed::NIP_INVALID, $e->reason);
        }
    }

    public function testLongNumberFailsAndTitleFits(): void
    {
        $s           = $this->snap();
        $s['number'] = str_repeat('1', 33);

        $this->assertTrue(PolishZbpQr::titleFits(str_repeat('1', 32)));
        $this->assertFalse(PolishZbpQr::titleFits(str_repeat('1', 33)));
        $this->expectException(QrPaymentFailed::class);
        (new PolishZbpQr())->encode($s);
    }

    public function testAmountAtLimitEncodesSixDigits(): void
    {
        $s                    = $this->snap();
        $s['totals']['total'] = '9999.99';

        $this->assertTrue(PolishZbpQr::amountFits('9999.99'));
        $this->assertStringStartsWith('1234563218|PL|61109010140000071219812874|999999|', (new PolishZbpQr())->encode($s));
    }

    public function testAmountAboveLimitDoesNotFitAndFails(): void
    {
        $this->assertFalse(PolishZbpQr::amountFits('10000.00'));
        $this->assertFalse(PolishZbpQr::amountFits('12500.00'));
        $this->assertTrue(PolishZbpQr::amountFits('0.00'));
        $this->assertTrue(PolishZbpQr::amountFits('0009999.99'));
        $this->assertFalse(PolishZbpQr::amountFits('abc'));

        $s                    = $this->snap();
        $s['totals']['total'] = '10000.00';
        $this->expectException(QrPaymentFailed::class);
        (new PolishZbpQr())->encode($s);
    }

    public function testApplies(): void
    {
        $this->assertTrue(PolishZbpQr::applies($this->snap()));

        $eur             = $this->snap();
        $eur['currency'] = 'EUR';
        $this->assertFalse(PolishZbpQr::applies($eur));

        $sk                 = $this->snap();
        $sk['bank']['iban'] = 'SK3112000000198742637541';
        $this->assertFalse(PolishZbpQr::applies($sk));
    }
}
