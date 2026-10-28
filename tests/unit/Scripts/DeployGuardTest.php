<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * D-08: scripts/deploy.sh (server: prod aj staging) nikdy nespusti migraciu bez
 * overenej zalohy a pri zlyhani nenahradi bezny stack. Chraní aj pred zamenou stackov
 * (COMPOSE_PROJECT_NAME, adresar projektu, siet edge). Skript bezi v docasnom adresari s falosnym
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
          *"network inspect"*)
            [ -z "${FAKE_NO_EDGE-}" ] || exit 1
            ;;
          *"image inspect"*)
            [ -z "${FAKE_NO_IMAGE-}" ] || exit 1
            ;;
          "ps "*)
            [ -z "${FAKE_PS_WORKDIR-}" ] || printf '%s\n' "$FAKE_PS_WORKDIR"
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
        case "$*" in
          -l) [ -z "${FAKE_CRONTAB_EXISTING-}" ] || printf '%s\n' "$FAKE_CRONTAB_EXISTING" ;;
          -) cat >> "$FAKE_LOG" ;;
        esac
        exit 0
        SH;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/deploy-guard-' . bin2hex(random_bytes(6));

        foreach (['', '/bin', '/certs', '/backups', '/secrets'] as $sub) {
            if (! mkdir($this->dir . $sub, 0o700, true)) {
                throw new RuntimeException('mkdir failed');
            }
        }
        copy(ROOTPATH . 'scripts/deploy.sh', $this->dir . '/deploy.sh');
        file_put_contents($this->dir . '/.env', "TLS_MODE = origin-ca\nAPP_DOMAIN = test.example\nCOMPOSE_PROJECT_NAME = test-stack\nEDGE_ALIAS = wt-test-nginx\nIMAGE_PREFIX = ghcr.io/x/tt\n");
        file_put_contents($this->dir . '/certs/origin.pem', '');

        // Rozlozenie tajomstiev podla WR-06 (falosne hodnoty).
        $this->writePrivate('/app.env', "CI_ENVIRONMENT = production\ndatabase.default.username = app_rw\ndatabase.default.password = fake-rw\n");
        $this->writePrivate('/migrate.env', "CI_ENVIRONMENT = production\ndatabase.default.password = fake-rw\ndatabase.owner.username = app_owner\ndatabase.owner.password = fake-owner\n");

        foreach (['db_superuser_password', 'db_owner_password', 'db_rw_password'] as $name) {
            $this->writePrivate('/secrets/' . $name, 'fake-' . $name);
        }
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

    public function testOldSecretLayoutFailsBeforeAnyDockerCall(): void
    {
        unlink($this->dir . '/app.env');
        unlink($this->dir . '/secrets/db_superuser_password');

        [$code, $err] = $this->runDeploy([]);

        $this->assertNotSame(0, $code);
        // Iba odhlasenie z GHCR (trap), ziadne volanie compose.
        $this->assertSame("logout ghcr.io\n", $this->log());
        $this->assertStringContainsString('app.env', $err);
        $this->assertStringContainsString('secrets/db_superuser_password', $err);
        $this->assertStringContainsString('sekcia 15', $err);
    }

    public function testPasswordsInComposeEnvFailBeforeAnyDockerCall(): void
    {
        file_put_contents($this->dir . '/.env', "DB_SUPERUSER_PASSWORD = fake\n", FILE_APPEND);

        [$code, $err] = $this->runDeploy([]);

        $this->assertNotSame(0, $code);
        // Iba odhlasenie z GHCR (trap), ziadne volanie compose.
        $this->assertSame("logout ghcr.io\n", $this->log());
        $this->assertStringContainsString('.env obsahuje', $err);
    }

    public function testOwnerCredentialsInAppEnvFailBeforeAnyDockerCall(): void
    {
        $this->writePrivate('/app.env', "database.default.password = fake-rw\ndatabase.owner.password = fake-owner\n");

        [$code, $err] = $this->runDeploy([]);

        $this->assertNotSame(0, $code);
        // Iba odhlasenie z GHCR (trap), ziadne volanie compose.
        $this->assertSame("logout ghcr.io\n", $this->log());
        $this->assertStringContainsString('app.env obsahuje', $err);
    }

    public function testWorldReadableSecretFailsBeforeAnyDockerCall(): void
    {
        chmod($this->dir . '/migrate.env', 0o644);

        [$code, $err] = $this->runDeploy([]);

        $this->assertNotSame(0, $code);
        // Iba odhlasenie z GHCR (trap), ziadne volanie compose.
        $this->assertSame("logout ghcr.io\n", $this->log());
        $this->assertStringContainsString('migrate.env je citatelny', $err);
    }

    public function testMissingProjectNameOrEdgeAliasFailsBeforeAnyDockerCall(): void
    {
        foreach (['COMPOSE_PROJECT_NAME', 'EDGE_ALIAS'] as $key) {
            file_put_contents($this->dir . '/.env', "TLS_MODE = origin-ca\nAPP_DOMAIN = test.example\n" . ($key === 'EDGE_ALIAS' ? "COMPOSE_PROJECT_NAME = test-stack\n" : "EDGE_ALIAS = wt-test-nginx\n"));
            @unlink($this->dir . '/calls.log');

            [$code, $err] = $this->runDeploy([]);

            $this->assertNotSame(0, $code);
            $this->assertSame("logout ghcr.io\n", $this->log());
            $this->assertStringContainsString($key, $err);
        }
    }

    public function testMissingEdgeNetworkFailsBeforePull(): void
    {
        [$code, $err] = $this->runDeploy(['FAKE_NO_EDGE' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('network inspect edge', $this->log());
        $this->assertStringNotContainsString('pull', $this->log());
        $this->assertStringContainsString('edge', $err);
    }

    public function testProjectFromAnotherDirectoryFailsBeforePull(): void
    {
        [$code, $err] = $this->runDeploy(['FAKE_PS_WORKDIR' => '/other']);

        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString('pull', $this->log());
        $this->assertStringContainsString('/other', $err);
        $this->assertStringContainsString(realpath($this->dir) ?: $this->dir, $err);
    }

    public function testSkipPullInspectsImagesInsteadOfPulling(): void
    {
        [$code, $err] = $this->runDeploy(['SKIP_PULL' => '1']);

        $this->assertSame(0, $code, $err);

        $log = $this->log();
        $this->assertStringNotContainsString('pull', $log);
        $this->assertStringContainsString('image inspect ghcr.io/x/tt-php:sha-test ghcr.io/x/tt-nginx:sha-test', $log);
        $this->assertLessThan(strpos($log, 'pg_dump'), strpos($log, 'image inspect'));
        $this->assertLessThan(strpos($log, 'up -d --wait --remove-orphans'), strpos($log, 'pg_dump'));
    }

    public function testCronKeepsOtherStacksAndWritesOneLineForThisDir(): void
    {
        $this->writeLetsEncryptEnv();
        $other = '17 3 * * * /opt/other/cert-renew.sh >> /opt/other/cert-renew.log 2>&1';
        $mine  = '17 3 * * * ' . (realpath($this->dir) ?: $this->dir) . '/cert-renew.sh >> old 2>&1';

        [$code, $err] = $this->runDeploy(['FAKE_CRONTAB_EXISTING' => $other . "\n" . $mine]);

        $this->assertSame(0, $code, $err);

        $log  = $this->log();
        $here = (realpath($this->dir) ?: $this->dir) . '/cert-renew.sh';
        $this->assertSame(1, substr_count($log, $other));
        $this->assertSame(1, substr_count($log, $here . ' >> ' . (realpath($this->dir) ?: $this->dir) . '/cert-renew.log'));
        $this->assertStringNotContainsString('>> old', $log);
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

        foreach (['pull', 'up -d --wait db', 'pg_dump', 'pg_restore -l', 'run --rm --no-deps -T migrate php spark migrate -g owner --all', 'up -d --wait --remove-orphans'] as $step) {
            $pos = strpos($log, $step);
            $this->assertNotFalse($pos, "chyba krok: {$step}");
            $this->assertGreaterThan($previous, $pos, "zle poradie kroku: {$step}");
            $previous = $pos;
        }

        $this->assertSame('sha-test', trim((string) file_get_contents($this->dir . '/.last-good-tag')));
        $this->assertCount(14, glob($this->dir . '/backups/pre-*.dump'));
    }

    private function writeLetsEncryptEnv(): void
    {
        file_put_contents($this->dir . '/.env', "TLS_MODE = letsencrypt\nAPP_DOMAIN = test.example\nLE_EMAIL = a@b.example\nCOMPOSE_PROJECT_NAME = test-stack\nEDGE_ALIAS = wt-test-nginx\nIMAGE_PREFIX = ghcr.io/x/tt\n");
        mkdir($this->dir . '/letsencrypt/live/test.example', 0o700, true);
        file_put_contents($this->dir . '/letsencrypt/live/test.example/fullchain.pem', '');
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

    private function writePrivate(string $relative, string $content): void
    {
        file_put_contents($this->dir . $relative, $content);
        chmod($this->dir . $relative, 0o600);
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
