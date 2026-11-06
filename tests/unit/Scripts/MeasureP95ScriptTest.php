<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * D-12: scripts/measure-p95.sh bezi s falosnym curl (P95_CURL), ziadna sietova poziadavka.
 *
 * @internal
 */
#[Group('unit')]
final class MeasureP95ScriptTest extends CIUnitTestCase
{
    private const FAKE_CURL = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_LOG"
        for a in "$@"; do
          case "$a" in @*) cat "${a#@}" >> "$FAKE_LOG.hdr" ;; esac
        done
        n=$(cat "$FAKE_DIR/count" 2>/dev/null || echo 0)
        n=$((n + 1))
        echo "$n" > "$FAKE_DIR/count"
        line=$(sed -n "${n}p" "$FAKE_DIR/values")
        [ -n "$line" ] || line="200 0.050"
        printf '%s\n' "$line"
        SH;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/p95-script-' . bin2hex(random_bytes(6));

        if (! mkdir($this->dir, 0o700, true)) {
            throw new RuntimeException('mkdir failed');
        }
        file_put_contents($this->dir . '/curl', self::FAKE_CURL . "\n");
        chmod($this->dir . '/curl', 0o700);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $f) {
            if (is_file((string) $f)) {
                unlink((string) $f);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    /**
     * @param list<string>          $values riadky "kod cas"
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runScript(array $values, array $env = []): array
    {
        file_put_contents($this->dir . '/values', implode("\n", $values) . "\n");
        $env += [
            'P95_CURL'   => $this->dir . '/curl',
            'P95_COOKIE' => 'SECRETCOOKIE',
            'P95_N'      => (string) count($values),
            'P95_WARMUP' => '0',
            'FAKE_LOG'   => $this->dir . '/curl.log',
            'FAKE_DIR'   => $this->dir,
            'PATH'       => getenv('PATH') ?: '/usr/bin:/bin',
        ];
        $proc = proc_open(
            ['sh', ROOTPATH . 'scripts/measure-p95.sh', 'https://x.test', '/tasks'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        $out  = (string) stream_get_contents($pipes[1]);
        $err  = (string) stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        return [$code, $out, $err];
    }

    /**
     * @return list<string>
     */
    private function steps(int $from, int $to, int $step): array
    {
        $v = [];

        for ($ms = $from; $ms <= $to; $ms += $step) {
            $v[] = sprintf('200 0.%03d', $ms);
        }

        return $v;
    }

    public function testP95OverLimitFails(): void
    {
        [$code, $out] = $this->runScript($this->steps(10, 200, 10));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('n=20 p50=100 p95=190 max=200 status=200', $out);
        $this->assertStringContainsString('ALL n=20 p95=190 limit=100 FAIL', $out);
    }

    public function testP95UnderLimitPasses(): void
    {
        [$code, $out] = $this->runScript($this->steps(10, 95, 5));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('OK', $out);
        $this->assertStringNotContainsString('FAIL', $out);
    }

    public function testWarmupAddsUnmeasuredRequests(): void
    {
        $values = array_fill(0, 5, '200 0.020');
        $this->runScript($values, ['P95_N' => '3', 'P95_WARMUP' => '2']);

        $lines = file($this->dir . '/curl.log', FILE_IGNORE_NEW_LINES);
        $this->assertCount(5, $lines);
    }

    public function testRedirectMeansInvalidCookie(): void
    {
        [$code, $out, $err] = $this->runScript(array_fill(0, 3, '302 0.020'));

        $this->assertSame(2, $code);
        $this->assertStringContainsString('cookie neplatná alebo expirovaná', $err);
        $this->assertStringNotContainsString('SECRETCOOKIE', $out . $err);
    }

    public function testCookieIsSentButNeverPrinted(): void
    {
        [, $out, $err] = $this->runScript($this->steps(10, 50, 10));

        $this->assertStringNotContainsString('SECRETCOOKIE', $out . $err);
        $this->assertStringContainsString('Cookie: SECRETCOOKIE', (string) file_get_contents($this->dir . '/curl.log.hdr'));
        $this->assertStringNotContainsString('SECRETCOOKIE', (string) file_get_contents($this->dir . '/curl.log'));
    }

    public function testResolveIsForwarded(): void
    {
        $this->runScript($this->steps(10, 30, 10), ['P95_RESOLVE' => 'a.b:443:1.2.3.4']);

        $this->assertStringContainsString('--resolve a.b:443:1.2.3.4', (string) file_get_contents($this->dir . '/curl.log'));
    }

    public function testMissingCookieRefused(): void
    {
        [$code, , $err] = $this->runScript($this->steps(10, 30, 10), ['P95_COOKIE' => '']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('chýba P95_COOKIE', $err);
    }
}
