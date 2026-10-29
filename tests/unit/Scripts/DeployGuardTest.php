<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
          *"network inspect mail"*)
            [ -z "${FAKE_NO_MAIL-}" ] || exit 1
            ;;
          *"network inspect"*)
            [ -z "${FAKE_NO_EDGE-}" ] || exit 1
            ;;
          *"image inspect"*)
            [ -z "${FAKE_NO_IMAGE-}" ] || exit 1
            ;;
          "ps "*)
            # Ako skutočný docker: .Labels je v `docker ps` reťazec, index na ňom zlyhá.
            case "$*" in *"index .Labels"*) echo 'failed to execute template' >&2; exit 1 ;; esac
            [ -z "${FAKE_PS_FAIL-}" ] || exit 1
            [ -z "${FAKE_PS_WORKDIR-}" ] || printf '%s\n' "$FAKE_PS_WORKDIR"
            ;;
          *"staging:seed"*)
            [ -z "${FAKE_SEED_FAIL-}" ] || exit 1
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
        copy(ROOTPATH . 'compose.server.mail.yaml', $this->dir . '/compose.server.mail.yaml');
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

    public function testWithoutMailNetworkOverlayIsNotUsed(): void
    {
        [$code, $err] = $this->runDeploy([]);

        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('-f compose.server.yaml', $this->log());
        $this->assertStringNotContainsString('compose.server.mail.yaml', $this->log());
        $this->assertStringNotContainsString('network inspect mail', $this->log());
    }

    public function testMailNetworkAddsOverlayAndChecksNetwork(): void
    {
        file_put_contents($this->dir . '/.env', "MAIL_NETWORK = mail\n", FILE_APPEND);

        [$code, $err] = $this->runDeploy([]);

        $this->assertSame(0, $code, $err);
        $log = $this->log();
        $this->assertStringContainsString('network inspect mail', $log);
        $this->assertStringContainsString('-f compose.server.yaml -f compose.server.mail.yaml', $log);
        $this->assertStringContainsString('compose -f compose.server.yaml -f compose.server.mail.yaml up -d --wait --remove-orphans', $log);
    }

    public function testMissingMailNetworkFailsBeforeBackupAndUp(): void
    {
        file_put_contents($this->dir . '/.env', "MAIL_NETWORK = mail\n", FILE_APPEND);

        [$code, $err] = $this->runDeploy(['FAKE_NO_MAIL' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Chýba docker sieť mail', $err);
        $this->assertStringNotContainsString('pg_dump', $this->log());
        $this->assertStringNotContainsString('up -d', $this->log());
    }

    public function testMissingMailOverlayFileFailsBeforeAnyDockerCall(): void
    {
        file_put_contents($this->dir . '/.env', "MAIL_NETWORK = mail\n", FILE_APPEND);
        unlink($this->dir . '/compose.server.mail.yaml');

        [$code, $err] = $this->runDeploy([]);

        $this->assertNotSame(0, $code);
        $this->assertSame("logout ghcr.io\n", $this->log());
        $this->assertStringContainsString('compose.server.mail.yaml', $err);
    }

    public function testProjectFromAnotherDirectoryFailsBeforePull(): void
    {
        [$code, $err] = $this->runDeploy(['FAKE_PS_WORKDIR' => '/other']);

        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString('pull', $this->log());
        $this->assertStringContainsString('/other', $err);
        $this->assertStringContainsString(realpath($this->dir) ?: $this->dir, $err);
    }

    public function testFailingProjectDirectoryCheckFailsBeforePull(): void
    {
        [$code, $err] = $this->runDeploy(['FAKE_PS_FAIL' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString('pull', $this->log());
        $this->assertStringContainsString('docker ps', $err);
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

    public function testWithoutStagingReseedNoResetOrSeedRuns(): void
    {
        // regresia: aj s `staging.seed = true` v app.env sa bez STAGING_RESEED nic nemaze ani nenaplna
        file_put_contents($this->dir . '/app.env', "staging.seed = true\n", FILE_APPEND);

        [$code, $err] = $this->runDeploy([]);

        $this->assertSame(0, $code, $err);
        $log = $this->log();
        $this->assertStringNotContainsString('DROP DATABASE', $log);
        $this->assertStringNotContainsString('provision.sql', $log);
        $this->assertStringNotContainsString('staging:seed', $log);
        $this->assertStringNotContainsString('seed_admin', $log);
    }

    public function testStagingReseedRunsResetMigrateAndSeedInOrder(): void
    {
        $this->enableReseed();

        [$code, $err] = $this->runDeploy([]);

        $this->assertSame(0, $code, $err);

        $log      = $this->log();
        $previous = -1;

        foreach (['pg_dump', 'pg_restore -l', 'DROP DATABASE IF EXISTS task_tracker WITH (FORCE)', 'provision.sql', 'run --rm --no-deps -T migrate php spark migrate -g owner --all', 'run --rm --no-deps -T seed', 'staging:seed', 'up -d --wait --remove-orphans'] as $step) {
            $pos = strpos($log, $step);
            $this->assertNotFalse($pos, "chyba krok: {$step}");
            $this->assertGreaterThan($previous, $pos, "zle poradie kroku: {$step}");
            $previous = $pos;
        }

        $this->assertSame('sha-test', trim((string) file_get_contents($this->dir . '/.last-good-tag')));
        // heslo seed admina ani db hesla nie su v ziadnom argumente dockeru
        $this->assertStringNotContainsString('fake-db_', $log);
        $this->assertStringContainsString('DB_OWNER_PASSWORD=$(cat "$DB_OWNER_PASSWORD_FILE")', $log);
    }

    #[DataProvider('provideStagingReseedRefusalsHappenBeforeAnyDockerCall')]
    public function testStagingReseedRefusalsHappenBeforeAnyDockerCall(string $case): void
    {
        $this->enableReseed();

        switch ($case) {
            case 'project':
                $this->setEnvKey('COMPOSE_PROJECT_NAME', 'task-tracker');
                break;

            case 'domain':
                $this->setEnvKey('APP_DOMAIN', 'work-tracker.vostack.dev');
                break;

            case 'missing':
                unlink($this->dir . '/secrets/seed_admin.env');
                break;

            case 'perm':
                chmod($this->dir . '/secrets/seed_admin.env', 0o644);
                break;

            case 'flag':
                $this->writePrivate('/app.env', "CI_ENVIRONMENT = production\ndatabase.default.username = app_rw\n");
                break;

            case 'typo':
                $this->setEnvKey('STAGING_RESEED', 'yes');
                break;
        }

        [$code, $err] = $this->runDeploy([]);

        $this->assertNotSame(0, $code);
        $this->assertSame("logout ghcr.io\n", $this->log());
        $this->assertStringContainsString('Nic sa nenasadilo', $err);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideStagingReseedRefusalsHappenBeforeAnyDockerCall(): iterable
    {
        yield 'production project' => ['project'];

        yield 'production domain' => ['domain'];

        yield 'missing seed_admin.env' => ['missing'];

        yield 'seed_admin.env readable by others' => ['perm'];

        yield 'app.env without staging.seed' => ['flag'];

        yield 'typo value' => ['typo'];
    }

    public function testProductionWithSeedFlagInAppEnvIsRefused(): void
    {
        $this->setEnvKey('COMPOSE_PROJECT_NAME', 'task-tracker');
        file_put_contents($this->dir . '/app.env', "staging.seed = true\n", FILE_APPEND);

        [$code, $err] = $this->runDeploy([]);

        $this->assertNotSame(0, $code);
        $this->assertSame("logout ghcr.io\n", $this->log());
        $this->assertStringContainsString('Produkcia', $err);
    }

    public function testProductionDirectoryIsHardcodedAsRefusedForReseed(): void
    {
        $script = (string) file_get_contents(ROOTPATH . 'scripts/deploy.sh');

        $this->assertStringContainsString('"$HERE" = /opt/work-tracker ]', $script);
    }

    public function testFailedSeedKeepsOldStack(): void
    {
        file_put_contents($this->dir . '/.last-good-tag', "sha-old\n");
        $this->enableReseed();

        [$code, $err] = $this->runDeploy(['FAKE_SEED_FAIL' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('staging:seed', $this->log());
        $this->assertStringNotContainsString('up -d --wait --remove-orphans', $this->log());
        $this->assertSame("sha-old\n", file_get_contents($this->dir . '/.last-good-tag'));
        $this->assertStringContainsString('Seed zlyhal', $err);
    }

    private function enableReseed(): void
    {
        file_put_contents($this->dir . '/.env', "STAGING_RESEED = 1\n", FILE_APPEND);
        file_put_contents($this->dir . '/app.env', "staging.seed = true\n", FILE_APPEND);
        $this->writePrivate('/secrets/seed_admin.env', "SEED_ADMIN_EMAIL = a@b.example\n");
    }

    private function setEnvKey(string $key, string $value): void
    {
        $lines   = file($this->dir . '/.env', FILE_IGNORE_NEW_LINES) ?: [];
        $lines   = array_values(array_filter($lines, static fn (string $l): bool => ! str_starts_with($l, $key . ' ')));
        $lines[] = $key . ' = ' . $value;
        file_put_contents($this->dir . '/.env', implode("\n", $lines) . "\n");
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
