<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * D-06: scripts/pull-backups.sh bezi s falosnym rsync a restic na PATH.
 *
 * @internal
 */
#[Group('unit')]
final class PullBackupsScriptTest extends CIUnitTestCase
{
    private const FAKE_RSYNC = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_RSYNC_LOG"
        [ -z "${FAKE_RSYNC_FAIL-}" ] || { echo "rsync: simulated failure" >&2; exit 23; }
        exit 0
        SH;
    private const FAKE_RESTIC = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_RESTIC_LOG"
        exit 0
        SH;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pull-script-' . bin2hex(random_bytes(6));

        foreach (['bin', 'home'] as $sub) {
            if (! mkdir($this->dir . '/' . $sub, 0o700, true)) {
                throw new RuntimeException('mkdir failed');
            }
        }

        foreach (['rsync' => self::FAKE_RSYNC, 'restic' => self::FAKE_RESTIC] as $n => $body) {
            file_put_contents($this->dir . '/bin/' . $n, $body . "\n");
            chmod($this->dir . '/bin/' . $n, 0o700);
        }
        file_put_contents($this->dir . '/pass', "x\n");
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
        parent::tearDown();
    }

    public function testRsyncArgumentsAreSafe(): void
    {
        [$code, $out] = $this->runScript();

        $this->assertSame(0, $code, $out);
        $log = (string) file_get_contents($this->dir . '/rsync.log');
        $this->assertStringContainsString('-a', $log);
        $this->assertStringContainsString('--partial', $log);
        $this->assertStringContainsString('--exclude /locks/', $log);
        $this->assertStringContainsString("-e ssh -i '" . $this->dir . "/key' -o IdentitiesOnly=yes -o BatchMode=yes", $log);
        $this->assertStringContainsString('backuppull@host.example: ' . $this->dir . '/dest/', $log);
        $this->assertStringNotContainsString('--delete', $log);
        $this->assertStringContainsString('OK: pull', $out);
    }

    public function testDestinationHasMode0700(): void
    {
        $this->runScript();

        clearstatcache();
        $this->assertSame('700', substr(sprintf('%o', fileperms($this->dir . '/dest')), -3));
    }

    public function testResticVerificationOnlyWithPasswordFile(): void
    {
        $this->runScript(['RESTIC_PASSWORD_FILE' => $this->dir . '/pass']);
        $restic = (string) file_get_contents($this->dir . '/restic.log');
        $this->assertStringContainsString('snapshots --compact --no-lock', $restic);
        $this->assertStringContainsString('check --no-lock', $restic);

        @unlink($this->dir . '/restic.log');
        $this->runScript();
        $this->assertFileDoesNotExist($this->dir . '/restic.log');
    }

    public function testRsyncFailureFails(): void
    {
        [$code, $out] = $this->runScript(['FAKE_RSYNC_FAIL' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('pull zlyhal', $out);
        $this->assertFileDoesNotExist($this->dir . '/restic.log');
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array{0: int, 1: string}
     */
    private function runScript(array $extra = []): array
    {
        @unlink($this->dir . '/rsync.log');
        $env = [
            'PATH'            => $this->dir . '/bin:/usr/local/bin:/usr/bin:/bin',
            'HOME'            => $this->dir . '/home',
            'PULL_SSH_TARGET' => 'backuppull@host.example',
            'PULL_SSH_KEY'    => $this->dir . '/key',
            'PULL_DEST'       => $this->dir . '/dest',
            'FAKE_RSYNC_LOG'  => $this->dir . '/rsync.log',
            'FAKE_RESTIC_LOG' => $this->dir . '/restic.log',
        ] + $extra;

        $proc = proc_open(['sh', ROOTPATH . 'scripts/pull-backups.sh'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);

        if (! is_resource($proc)) {
            throw new RuntimeException('proc_open failed');
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out];
    }

    private function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->rmrf($path . '/' . $e);
            }
        }
        rmdir($path);
    }
}
