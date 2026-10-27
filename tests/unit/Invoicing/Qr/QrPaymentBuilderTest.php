<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing\Qr;

use App\Libraries\Invoicing\Qr\QrPaymentBuilder;
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
}
