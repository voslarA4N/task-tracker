<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing\Qr;

use App\Libraries\Invoicing\Qr\QrPaymentFailed;
use App\Libraries\Invoicing\Qr\XzBinaryLzma;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('integration')]
final class XzBinaryLzmaTest extends CIUnitTestCase
{
    public function testXzAvailable(): void
    {
        $this->assertTrue(XzBinaryLzma::available());
    }

    public function testCompressDecompressIdentity(): void
    {
        $raw  = bin2hex(random_bytes(200)) . "\tabc\t\t";
        $comp = (new XzBinaryLzma())->compress($raw);

        $pipes = [];
        $proc  = proc_open(['xz', '-dc', '--format=raw', '--lzma1=lc=3,lp=0,pb=2,dict=128KiB'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $comp);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertSame($raw, $out);
    }

    public function testHungProcessIsKilledAfterTimeout(): void
    {
        try {
            (new XzBinaryLzma(['sleep', '10'], 300))->compress('abc');
            $this->fail('Expected QrPaymentFailed');
        } catch (QrPaymentFailed $e) {
            $this->assertSame(QrPaymentFailed::COMPRESSION_FAILED, $e->reason);
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function testEarlyExitWithLargeInputIsQrPaymentFailed(): void
    {
        // proces skonci bez citania stdin: zapis zlyha (broken pipe) alebo stdout skonci skor, nikdy ErrorException
        $this->expectException(QrPaymentFailed::class);
        (new XzBinaryLzma(['sh', '-c', 'exit 3']))->compress(str_repeat('x', 1_000_000));
    }

    public function testNonZeroExitIsQrPaymentFailed(): void
    {
        $this->expectException(QrPaymentFailed::class);
        (new XzBinaryLzma(['sh', '-c', 'cat >/dev/null; echo bad >&2; exit 2']))->compress('abc');
    }

    public function testLargeOutputOnBothStreamsDoesNotDeadlock(): void
    {
        $out = (new XzBinaryLzma(['sh', '-c', 'cat; head -c 200000 /dev/zero >&2']))->compress(str_repeat('y', 300_000));
        $this->assertSame(300_000, strlen($out));
    }
}
