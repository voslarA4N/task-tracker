<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * D-08: scripts/deploy.sh nikdy nespusti migraciu bez overenej zalohy a pri
 * zlyhani nenahradi bezny stack. Skript bezi v docasnom adresari s falosnym
 * `docker` a `crontab` na PATH, ktore len zapisuju volania do calls.log.
 *
 * @internal
 */
#[Group('unit')]
final class DeployGuardTest extends CIUnitTestCase
{
    private const FAKE_DOCKER = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_LOG"
        case "$*" in
          *pg_dump*)
            [ -z "${FAKE_DUMP_EMPTY-}" ] || exit 0
            printf 'PGDMP-fake-dump'
            ;;
          *"pg_restore -l"*)
            [ -z "${FAKE_RESTORE_FAIL-}" ] || exit 1
            ;;
          *migrate*)
            [ -z "${FAKE_MIGRATE_FAIL-}" ] || exit 1
            ;;
        esac
        exit 0
        SH;

    private const FAKE_CRONTAB = <<<'SH'
        #!/bin/sh
        printf 'crontab %s\n' "$*" >> "$FAKE_LOG"
        exit 0
        SH;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/deploy-guard-' . bin2hex(random_bytes(6));

        foreach (['', '/bin', '/certs', '/backups'] as $sub) {
            if (! mkdir($this->dir . $sub, 0o700, true)) {
                throw new RuntimeException('mkdir failed');
            }
        }
        copy(ROOTPATH . 'scripts/deploy.sh', $this->dir . '/deploy.sh');
        file_put_contents($this->dir . '/.env', "TLS_MODE = origin-ca\nAPP_DOMAIN = test.example\n");
        file_put_contents($this->dir . '/certs/origin.pem', '');
        file_put_contents($this->dir . '/bin/docker', self::FAKE_DOCKER . "\n");
        file_put_contents($this->dir . '/bin/crontab', self::FAKE_CRONTAB . "\n");
        chmod($this->dir . '/bin/docker', 0o700);
        chmod($this->dir . '/bin/crontab', 0o700);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
        parent::tearDown();
    }

    public function testMissingImageTagFailsBeforeAnyDockerCall(): void
    {
        [$code] = $this->runDeploy(['IMAGE_TAG' => '']);

        $this->assertNotSame(0, $code);
        $this->assertSame('', $this->log());
    }

    public function testEmptyDumpBlocksMigration(): void
    {
        [$code] = $this->runDeploy(['FAKE_DUMP_EMPTY' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('pg_dump', $this->log());
        $this->assertStringNotContainsString('migrate', $this->log());
        $this->assertFileDoesNotExist($this->dir . '/.last-good-tag');
    }

    public function testUnreadableDumpBlocksMigration(): void
    {
        [$code] = $this->runDeploy(['FAKE_RESTORE_FAIL' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('pg_restore -l', $this->log());
        $this->assertStringNotContainsString('migrate', $this->log());
    }

    public function testFailedMigrationKeepsOldStack(): void
    {
        file_put_contents($this->dir . '/.last-good-tag', "sha-old\n");
        [$code] = $this->runDeploy(['FAKE_MIGRATE_FAIL' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('migrate -g owner --all', $this->log());
        $this->assertStringNotContainsString('up -d --wait --remove-orphans', $this->log());
        $this->assertSame("sha-old\n", file_get_contents($this->dir . '/.last-good-tag'));
    }

    public function testSuccessfulDeployRunsStepsInOrderAndRotatesBackups(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            file_put_contents(sprintf('%s/backups/pre-20200101T0000%02dZ.dump', $this->dir, $i), 'old');
        }

        [$code, $err] = $this->runDeploy([]);

        $this->assertSame(0, $code, $err);

        $log      = $this->log();
        $previous = -1;

        foreach (['pull', 'up -d --wait db', 'pg_dump', 'pg_restore -l', 'migrate -g owner --all', 'up -d --wait --remove-orphans'] as $step) {
            $pos = strpos($log, $step);
            $this->assertNotFalse($pos, "chyba krok: {$step}");
            $this->assertGreaterThan($previous, $pos, "zle poradie kroku: {$step}");
            $previous = $pos;
        }

        $this->assertSame('sha-test', trim((string) file_get_contents($this->dir . '/.last-good-tag')));
        $this->assertCount(14, glob($this->dir . '/backups/pre-*.dump'));
    }

    /**
     * @param array<string,string> $env
     *
     * @return array{0:int,1:string}
     */
    private function runDeploy(array $env): array
    {
        $env = array_merge([
            'PATH'       => $this->dir . '/bin:/usr/bin:/bin',
            'IMAGE_TAG'  => 'sha-test',
            'BACKUP_DIR' => $this->dir . '/backups',
            'FAKE_LOG'   => $this->dir . '/calls.log',
            'HOME'       => $this->dir,
        ], $env);

        $proc = proc_open(
            ['sh', $this->dir . '/deploy.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            $env,
        );
        $this->assertIsResource($proc);
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $err];
    }

    private function log(): string
    {
        $f = $this->dir . '/calls.log';

        return is_file($f) ? (string) file_get_contents($f) : '';
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $path . '/' . $item;
            is_dir($p) ? $this->removeTree($p) : unlink($p);
        }
        rmdir($path);
    }
}
