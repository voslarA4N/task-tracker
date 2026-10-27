<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing\Qr;

use App\Libraries\Invoicing\Qr\Lzma;
use App\Libraries\Invoicing\Qr\PayBySquare;
use App\Libraries\Invoicing\Qr\QrPaymentFailed;
use App\Libraries\Invoicing\Samples\SampleSnapshots;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PayBySquareDecoder;

/**
 * @internal
 */
#[Group('unit')]
final class PayBySquareTest extends CIUnitTestCase
{
    public function testBase32hex(): void
    {
        $this->assertSame('0400', PayBySquare::base32hex("\x01\x00"));
        $this->assertMatchesRegularExpression('/^[0-9A-V]+$/', PayBySquare::base32hex(random_bytes(37)));
    }

    public function testAmount(): void
    {
        $this->assertSame('1080.5', PayBySquare::amount('1080.50'));
        $this->assertSame('1080', PayBySquare::amount('1080.00'));
        $this->assertSame('0.05', PayBySquare::amount('0.05'));
    }

    public function testAscii(): void
    {
        $this->assertSame('Tomas Voslar - Zilina', PayBySquare::ascii('Tomáš Voslař – Žilina'));
        $this->assertSame('a b', PayBySquare::ascii("a\tb"));
    }

    public function testTabFieldsOrder(): void
    {
        $s      = SampleSnapshots::invoice('sk');
        $fields = (new PayBySquare())->tabFields($s);

        $this->assertSame('', $fields[0]);
        $this->assertSame(['1', '1', '1309.5', 'EUR', '20261109', '202610001', '', '', ''], array_slice($fields, 1, 9));
        $this->assertSame($s['labels']['invoice'] === '' ? '' : PayBySquare::ascii($s['labels']['invoice'] . ' 202610001'), $fields[10]);
        $this->assertSame(['1', 'SK3112000000198742637541', 'GIBASKBX', '0', '0', 'Tomas Voslar - All4Net', 'Hlavna 1', '01001 Zilina'], array_slice($fields, 11));
    }

    public function testRoundTrip(): void
    {
        $s       = SampleSnapshots::invoice('sk');
        $pbs     = new PayBySquare();
        $decoded = PayBySquareDecoder::decode($pbs->encode($s));

        $this->assertSame(chr(PayBySquare::VERSION) . "\x00", $decoded['header']);
        $this->assertTrue($decoded['crc_ok']);
        $this->assertSame($decoded['raw_len'], $decoded['length']);
        $this->assertSame($pbs->tabFields($s), $decoded['fields']);
    }

    public function testLzmaFailurePropagates(): void
    {
        $lzma = new class () implements Lzma {
            public function compress(string $raw): string
            {
                throw new QrPaymentFailed('boom');
            }
        };

        $this->expectException(QrPaymentFailed::class);
        (new PayBySquare($lzma))->encode(SampleSnapshots::invoice('sk'));
    }

    public function testNumberRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PayBySquare())->tabFields(SampleSnapshots::invoice('sk', null, ['number' => null]));
    }
}
