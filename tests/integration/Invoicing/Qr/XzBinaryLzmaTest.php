<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing\Qr;

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
}
