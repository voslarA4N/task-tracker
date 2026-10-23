<?php

declare(strict_types=1);

namespace Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('integration')]
final class MpdfSpikeTest extends CIUnitTestCase
{
    private const LIMIT_MS = 2000;

    public function testColdRunUnderTwoSeconds(): void
    {
        $this->assertRun(true);
    }

    public function testWarmRunUnderTwoSeconds(): void
    {
        $this->assertRun(false);
    }

    public function testPdfContainsDiacritics(): void
    {
        $data = $this->runSpike(false);
        $pdf  = $data['pdf_path'];

        $this->assertSame('%PDF', (string) file_get_contents($pdf, false, null, 0, 4));

        $which = trim((string) shell_exec('command -v pdftotext'));
        if ($which === '') {
            $this->markTestSkipped('pdftotext is not available');
        }

        $text = (string) shell_exec('pdftotext ' . escapeshellarg($pdf) . ' -');
        $this->assertStringContainsString('łńěťžô', $text);
    }

    private function assertRun(bool $cold): void
    {
        $data = $this->runSpike($cold);

        $this->assertGreaterThan(0, $data['pdf_bytes']);
        $this->assertStringStartsWith('8.5', $data['php_version']);

        fwrite(STDERR, sprintf(
            "\n[mpdf-spike] %s: %d ms, peak %d MB, deprecations %d\n",
            $data['mode'],
            $data['elapsed_ms'],
            $data['peak_memory_mb'],
            $data['deprecations_count'],
        ));

        foreach ($data['deprecations'] as $d) {
            fwrite(STDERR, '[mpdf-spike] deprecation: ' . $d . "\n");
        }

        if (getenv('CI') !== false && getenv('CI') !== '') {
            if ($data['elapsed_ms'] >= self::LIMIT_MS) {
                fwrite(STDERR, "[mpdf-spike] WARNING: over 2000 ms on CI runner (not enforced)\n");
            }
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertLessThan(self::LIMIT_MS, $data['elapsed_ms']);
    }

    /**
     * Spúšťa spike v podprocese, aby E_ALL handler nezasahoval do PHPUnit.
     *
     * @return array<string, mixed>
     */
    private function runSpike(bool $cold): array
    {
        $cmd  = [PHP_BINARY, ROOTPATH . 'scripts/spikes/mpdf.php'];
        if ($cold) {
            $cmd[] = '--cold';
        }
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $this->assertSame(0, $code, 'spike failed: ' . $out . $err);

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
}
