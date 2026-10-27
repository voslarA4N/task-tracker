<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * D-24: scripts/backup.sh bezi v docasnom adresari s falosnym `docker` na PATH. Skutocna DB sa nedotkne.
 *
 * @internal
 */
#[Group('unit')]
final class BackupScriptTest extends CIUnitTestCase
{
    private const FAKE_DOCKER = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_LOG"
        case "$*" in
          *pg_dump*)
            [ -z "${FAKE_DUMP_FAIL-}" ] || exit 1
            printf 'PGDMP-fake-dump'
            ;;
          *"pg_restore --list"*)
            cat > /dev/null
            [ -z "${FAKE_LIST_FAIL-}" ] || exit 1
            ;;
        esac
        exit 0
        SH;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/backup-script-' . bin2hex(random_bytes(6));

        foreach (['scripts', 'bin', 'out'] as $sub) {
            if (! mkdir($this->dir . '/' . $sub, 0o700, true)) {
                throw new RuntimeException('mkdir failed');
            }
        }
        copy(ROOTPATH . 'scripts/backup.sh', $this->dir . '/scripts/backup.sh');
        file_put_contents($this->dir . '/bin/docker', self::FAKE_DOCKER . "\n");
        chmod($this->dir . '/bin/docker', 0o700);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/out/*') as $f) {
            unlink((string) $f);
        }

        foreach (['docker.log', 'scripts/backup.sh', 'bin/docker'] as $f) {
            if (is_file($this->dir . '/' . $f)) {
                unlink($this->dir . '/' . $f);
            }
        }

        foreach (['scripts', 'bin', 'out', ''] as $d) {
            if (is_dir($this->dir . '/' . $d)) {
                rmdir($this->dir . '/' . $d);
            }
        }
        parent::tearDown();
    }

    public function testCreatesVerifiedDumpWithChecksum(): void
    {
        [$code, $stderr] = $this->run2([]);

        $this->assertSame(0, $code, $stderr);
        $dumps = glob($this->dir . '/out/task_tracker-*.dump');
        $this->assertIsArray($dumps);
        $this->assertCount(1, $dumps);
        $this->assertSame('PGDMP-fake-dump', file_get_contents($dumps[0]));
        $this->assertSame([], glob($this->dir . '/out/*.partial'));
        $this->assertSame(0o600, fileperms($dumps[0]) & 0o777);

        $sha = $dumps[0] . '.sha256';
        $this->assertFileExists($sha);
        $this->assertStringStartsWith(hash('sha256', 'PGDMP-fake-dump'), (string) file_get_contents($sha));
        $this->assertSame(0o600, fileperms($sha) & 0o777);

        $log = (string) file_get_contents($this->dir . '/docker.log');
        $this->assertStringContainsString('pg_dump -U app_owner --format=custom --no-owner task_tracker', $log);
    }

    public function testDumpFailureLeavesNothing(): void
    {
        [$code] = $this->run2(['FAKE_DUMP_FAIL' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertSame([], glob($this->dir . '/out/*'));
    }

    public function testListFailureLeavesNothing(): void
    {
        [$code] = $this->run2(['FAKE_LIST_FAIL' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertSame([], glob($this->dir . '/out/*'));
    }

    public function testEnvironmentOverrides(): void
    {
        [$code, $stderr] = $this->run2(['BACKUP_DB' => 'x', 'BACKUP_DB_USER' => 'y']);

        $this->assertSame(0, $code, $stderr);
        $this->assertStringContainsString('pg_dump -U y --format=custom --no-owner x', (string) file_get_contents($this->dir . '/docker.log'));
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array{0: int, 1: string}
     */
    private function run2(array $extra): array
    {
        $env  = ['PATH' => $this->dir . '/bin:/usr/local/bin:/usr/bin:/bin', 'FAKE_LOG' => $this->dir . '/docker.log'] + $extra;
        $proc = proc_open(['sh', 'scripts/backup.sh', $this->dir . '/out'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);

        if (! is_resource($proc)) {
            throw new RuntimeException('proc_open failed');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stderr];
    }
}
