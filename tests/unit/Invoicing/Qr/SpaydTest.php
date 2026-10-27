<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing\Qr;

use App\Libraries\Invoicing\Qr\QrPaymentFailed;
use App\Libraries\Invoicing\Qr\Spayd;
use App\Libraries\Invoicing\Samples\SampleSnapshots;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class SpaydTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function snap(): array
    {
        $s                            = SampleSnapshots::invoice('sk');
        $s['number']                  = '2610001';
        $s['variable_symbol']         = '2610001';
        $s['totals']['total']         = '1080.50';
        $s['due_date']                = '2026-11-10';
        $s['labels']['invoice']       = 'Faktúra';
        $s['entity']['trade_name']    = 'Jan Novák';
        $s['bank']['iban']            = 'SK3112000000198742637541';
        $s['bank']['swift']           = 'TATRSKBX';

        return $s;
    }

    public function testReferenceVector(): void
    {
        $this->assertSame(
            'SPD*1.0*ACC:SK3112000000198742637541+TATRSKBX*AM:1080.50*CC:EUR*DT:20261110*X-VS:2610001*MSG:FAKTURA 2610001*RN:JAN NOVAK*',
            (new Spayd())->encode($this->snap()),
        );
    }

    public function testAsteriskEscapedAndNoBic(): void
    {
        $s                         = $this->snap();
        $s['entity']['trade_name'] = 'A*B';
        $s['bank']['swift']        = null;
        $out                       = (new Spayd())->encode($s);

        $this->assertStringContainsString('RN:A%2AB*', $out);
        $this->assertStringContainsString('ACC:SK3112000000198742637541*', $out);
    }

    public function testAmountTooLong(): void
    {
        $s                    = $this->snap();
        $s['totals']['total'] = '12345678.90';
        $this->expectException(QrPaymentFailed::class);
        (new Spayd())->encode($s);
    }
}
