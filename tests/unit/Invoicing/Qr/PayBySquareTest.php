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
        // Adresa príjemcu ostáva prázdna: aplikácia ČSOB pri vyplnenej ulici alebo meste hlási chybu (UAT 2026-10-28).
        $this->assertSame(['1', 'SK3112000000198742637541', 'GIBASKBX', '0', '0', 'Tomas Voslar - All4Net', '', ''], array_slice($fields, 11));
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

    /**
     * Zlatý vektor z referenčnej knižnice bysquare 4.1.0 (xseman/bysquare, version 1.0.0,
     * beneficiary iba s menom) pre rovnaké údaje ako SampleSnapshots::invoice('sk'). Hlavička 1.0.0
     * (prvé znaky "00"), lebo ČSOB SK (UAT 2026-10-28) 1.1.0 ani 1.2.0 nerozpozná.
     */
    public function testMatchesReferenceEncoder(): void
    {
        $this->assertSame(
            '0007O000FUDOQIN09C62MCHVP22M7D8BLOH3M54107BDP865TORPHNRNCPCCI2GBEDLP2RPRUM50T46SM53KIGASUAVH0Q8IPQMQGDU2KUBIM8JTAFJUTJ7858R1BH3IRK849OBTR6MT7AGB0MJ10E7UAIVBOEEJGO9VMG8CUN2VO13VVVJO9K00',
            (new PayBySquare())->encode(SampleSnapshots::invoice('sk')),
        );
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
