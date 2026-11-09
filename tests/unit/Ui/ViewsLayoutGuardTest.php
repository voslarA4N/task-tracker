<?php

declare(strict_types=1);

namespace Tests\Unit\Ui;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Staticke strazce pohladov (D-11, D-12): ziadny inline style, dotykove ciele, responzivne tabulky.
 *
 * @internal
 */
#[Group('unit')]
final class ViewsLayoutGuardTest extends CIUnitTestCase
{
    /**
     * @return array<string, string> path => source
     */
    private function views(): array
    {
        $out = [];
        $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH . 'Views', RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php' || str_contains($path, '/pdf/') || str_contains($path, '/errors/')) {
                continue;
            }
            $out[$path] = (string) file_get_contents($path);
        }

        return $out;
    }

    private function line(string $src, int $offset): int
    {
        return substr_count($src, "\n", 0, $offset) + 1;
    }

    public function testNoInlineStyleInViews(): void
    {
        $problems = [];

        foreach ($this->views() as $path => $src) {
            if (preg_match_all('/\sstyle\s*=\s*"/', $src, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [, $off]) {
                    $problems[] = $path . ':' . $this->line($src, $off);
                }
            }
        }

        $this->assertSame([], $problems, "Inline style v pohladoch:\n" . implode("\n", $problems));
    }

    public function testSmallButtonsKeepTouchTarget(): void
    {
        $problems = [];

        foreach ($this->views() as $path => $src) {
            if (preg_match_all('/class="[^"]*\bbtn-sm\b[^"]*"/', $src, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [$cls, $off]) {
                    if (! str_contains($cls, 'btn-touch')) {
                        $problems[] = $path . ':' . $this->line($src, $off);
                    }
                }
            }
        }

        $this->assertSame([], $problems, "btn-sm bez btn-touch:\n" . implode("\n", $problems));
    }

    /**
     * Heuristika: pred kazdou <table musi byt (po predchadzajucom </table>) otvaraci tag
     * s triedou table-responsive alebo dt-only-lg.
     */
    public function testTablesAreResponsive(): void
    {
        $problems = [];

        foreach ($this->views() as $path => $src) {
            $offset = 0;

            while (($pos = strpos($src, '<table', $offset)) !== false) {
                $prevClose = strrpos(substr($src, 0, $pos), '</table>');
                $segment   = substr($src, $prevClose === false ? 0 : $prevClose, $pos - ($prevClose === false ? 0 : $prevClose));

                if (preg_match('/class="[^"]*\b(table-responsive|dt-only-lg)\b/', $segment) !== 1) {
                    $problems[] = $path . ':' . $this->line($src, $pos);
                }
                $offset = $pos + 6;
            }
        }

        $this->assertSame([], $problems, "Tabulka mimo table-responsive/dt-only-lg:\n" . implode("\n", $problems));
    }
}
