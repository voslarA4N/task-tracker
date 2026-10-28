<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing\Qr;

use App\Libraries\Invoicing\Qr\QrPaymentBuilder;
use App\Libraries\Invoicing\Qr\QrPaymentFailed;
use App\Libraries\Invoicing\Samples\SampleSnapshots;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class QrPaymentBuilderTest extends CIUnitTestCase
{
    public function testSkTransfer(): void
    {
        $qr = (new QrPaymentBuilder())->build(SampleSnapshots::invoice('sk'));

        $this->assertNotNull($qr);
        $this->assertSame('pay_by_square', $qr['standard']);
        $this->assertNotSame('', $qr['payload']);
    }

    public function testNonTransferHasNoQr(): void
    {
        foreach (['cash', 'card', 'other'] as $method) {
            $s = SampleSnapshots::invoice('sk', null, ['payment' => ['method' => $method, 'note' => null, 'qr' => null]]);
            $this->assertNull((new QrPaymentBuilder())->build($s));
        }
    }

    public function testOtherCountryHasNoStandard(): void
    {
        $s                      = SampleSnapshots::invoice('sk');
        $s['client']['country'] = 'DE';

        $this->assertNull((new QrPaymentBuilder())->build($s));
    }

    public function testNumberRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QrPaymentBuilder())->build(SampleSnapshots::invoice('sk', null, ['number' => null]));
    }

    public function testCzTransferIsSpayd(): void
    {
        $qr = (new QrPaymentBuilder())->build(SampleSnapshots::invoice('cz'));
        $this->assertSame('spayd', $qr['standard'] ?? null);
        $this->assertStringStartsWith('SPD*1.0*', $qr['payload']);
    }

    public function testCzCashHasNoQr(): void
    {
        $s = SampleSnapshots::invoice('cz', null, ['payment' => ['method' => 'cash', 'note' => null, 'qr' => null]]);
        $this->assertNull((new QrPaymentBuilder())->build($s));
    }

    public function testPlPlnIsZbp(): void
    {
        $qr = (new QrPaymentBuilder())->build(SampleSnapshots::invoice('pl'));
        $this->assertSame('zbp', $qr['standard'] ?? null);
    }

    public function testPlLongNumberHasNoQr(): void
    {
        $s           = SampleSnapshots::invoice('pl');
        $s['number'] = str_repeat('1', 33);
        $this->assertNull((new QrPaymentBuilder())->build($s));
    }

    public function testPlAmountAtLimitHasZbpQr(): void
    {
        $s                    = SampleSnapshots::invoice('pl');
        $s['totals']['total'] = '9999.99';
        $qr                   = (new QrPaymentBuilder())->build($s);

        $this->assertNotNull($qr);
        $this->assertSame('zbp', $qr['standard']);
        $this->assertStringContainsString('|999999|', $qr['payload']);
    }

    public function testPlAmountAboveLimitHasNoQr(): void
    {
        $s                    = SampleSnapshots::invoice('pl');
        $s['totals']['total'] = '10000.00';
        $this->assertNull((new QrPaymentBuilder())->build($s));
    }

    public function testPlWithoutNipFails(): void
    {
        $s                     = SampleSnapshots::invoice('pl');
        $s['entity']['tax_id'] = null;
        $this->expectException(QrPaymentFailed::class);
        (new QrPaymentBuilder())->build($s);
    }

    public function testPlWithSkIbanHasNoQr(): void
    {
        $s                 = SampleSnapshots::invoice('pl');
        $s['bank']['iban'] = 'SK3112000000198742637541';
        $this->assertNull((new QrPaymentBuilder())->build($s));
    }
}
