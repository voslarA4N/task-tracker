<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * D-07, D-09: scripts/backup-restic.sh bezi v docasnom adresari s falosnym docker, restic a curl na PATH.
 *
 * @internal
 */
#[Group('unit')]
final class ResticBackupScriptTest extends CIUnitTestCase
{
    private const FAKE_DOCKER = <<<'SH'
        #!/bin/sh
        printf '%s | pwd=%s\n' "$*" "$(pwd)" >> "$FAKE_LOG"
        case "$*" in
          *pg_dump*)
            [ -z "${FAKE_DUMP_FAIL-}" ] || { echo "pg_dump: simulated failure" >&2; exit 1; }
            : > "$FAKE_LOG.dumped"
            printf 'PGDMP-fake-dump'
            ;;
          *"pg_restore -f /dev/null"*)
            cat > /dev/null
            ;;
          *psql*)
            cat > /dev/null
            [ -z "${FAKE_PSQL_FAIL-}" ] || { echo "psql: simulated failure" >&2; exit 1; }
            case "$*" in
              *"count(*) FROM invoices")
                if [ -n "${FAKE_CHANGE_ALWAYS-}" ] && [ -f "$FAKE_LOG.dumped" ]; then date +%N; exit 0; fi
                if [ -n "${FAKE_CHANGE_ONCE-}" ] && [ -f "$FAKE_LOG.dumped" ] && [ ! -f "$FAKE_LOG.chg" ]; then : > "$FAKE_LOG.chg"; echo 6; exit 0; fi
                echo 5 ;;
              *"number FROM invoices"*) echo "2026-0007" ;;
              *"md5(string_agg"*) echo "digestabc" ;;
              *"encode(sha256(content)"*) echo "${FAKE_BAD_SHA-0}" ;;
              *) echo "5" ;;
            esac
            ;;
        esac
        exit 0
        SH;
    private const FAKE_RESTIC = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_RESTIC_LOG"
        case "$1" in
          cat)
            [ "${FAKE_RESTIC_FAIL-}" != config ] || { echo "Fatal: unable to open config file" >&2; exit 1; }
            ;;
          backup)
            [ "${FAKE_RESTIC_FAIL-}" != backup ] || { echo "restic backup simulated failure" >&2; exit 1; }
            for last in "$@"; do :; done
            rm -rf "$FAKE_SNAP"; mkdir -p "$FAKE_SNAP"; cp "$last"/* "$FAKE_SNAP"/
            ;;
          forget)
            [ "${FAKE_RESTIC_FAIL-}" != forget ] || { echo "restic forget simulated failure" >&2; exit 1; }
            ;;
        esac
        exit 0
        SH;
    private const FAKE_CURL = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_CURL_LOG"
        for a in "$@"; do
          [ "$a" != -K ] || cat >> "$FAKE_CURL_LOG.cfg"
        done
        prev=
        for a in "$@"; do
          [ "$prev" != --upload-file ] || cat "$a" >> "$FAKE_MAIL"
          prev=$a
        done
        exit 0
        SH;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/restic-script-' . bin2hex(random_bytes(6));

        foreach (['scripts', 'bin', 'stage', 'stack'] as $sub) {
            if (! mkdir($this->dir . '/' . $sub, 0o700, true)) {
                throw new RuntimeException('mkdir failed');
            }
        }

        foreach (['backup.sh', 'lib-restic-policy.sh', 'lib-backup-notify.sh', 'backup-restic.sh'] as $f) {
            if (is_file(ROOTPATH . 'scripts/' . $f)) {
                copy(ROOTPATH . 'scripts/' . $f, $this->dir . '/scripts/' . $f);
            }
        }

        foreach (['docker' => self::FAKE_DOCKER, 'restic' => self::FAKE_RESTIC, 'curl' => self::FAKE_CURL] as $n => $body) {
            file_put_contents($this->dir . '/bin/' . $n, $body . "\n");
            chmod($this->dir . '/bin/' . $n, 0o700);
        }
        file_put_contents($this->dir . '/restic-pass', "RESTIC-PASS-TOPSECRET\n");
        file_put_contents($this->dir . '/smtp-pass', "SMTP-PASS-TOPSECRET\n");
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
        parent::tearDown();
    }

    public function testSuccessStoresSnapshotRotatesAndPings(): void
    {
        [$code, $out] = $this->runScript();

        $this->assertSame(0, $code, $out);
        $restic = (string) file_get_contents($this->dir . '/restic.log');
        $this->assertStringContainsString('backup --tag db --host work-tracker ' . $this->dir . '/stage', $restic);
        $this->assertStringContainsString('forget --group-by host,tags --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --keep-tag archive --prune', $restic);

        $curl = (string) file_get_contents($this->dir . '/curl.log.cfg');
        $this->assertStringContainsString('https://hc.example/ping/SECRET-UUID', $curl);
        $this->assertStringNotContainsString('/fail', $curl);
        $this->assertStringNotContainsString('SECRET-UUID', (string) file_get_contents($this->dir . '/curl.log'));
        $this->assertFileDoesNotExist($this->dir . '/mail.eml');

        $this->assertSame([], glob($this->dir . '/stage/*.dump*'));
        $this->assertFileDoesNotExist($this->dir . '/stage/manifest.txt');

        $manifest = (string) file_get_contents($this->dir . '/snap/manifest.txt');
        $this->assertStringContainsString('documents_bad_sha256=0', $manifest);
        $this->assertStringContainsString('last_invoice_number=2026-0007', $manifest);
        $this->assertStringContainsString('rows.invoices=5', $manifest);
        $this->assertStringContainsString('env=test', $manifest);
        $this->assertSame('PGDMP-fake-dump', file_get_contents($this->dir . '/snap/task_tracker.dump'));
        $this->assertFileExists($this->dir . '/snap/task_tracker.dump.sha256');
        $this->assertStringContainsString('dump_sha256=' . hash('sha256', 'PGDMP-fake-dump'), $manifest);

        $this->assertNoSecrets($out);
    }

    public function testDumpFailureAlertsAndSkipsRestic(): void
    {
        [$code, $out] = $this->runScript(['FAKE_DUMP_FAIL' => '1']);

        $this->assertNotSame(0, $code);
        $cfg  = (string) file_get_contents($this->dir . '/curl.log.cfg');
        $curl = (string) file_get_contents($this->dir . '/curl.log');
        $this->assertStringContainsString('https://hc.example/ping/SECRET-UUID/fail', $cfg);
        $this->assertStringNotContainsString('SECRET-UUID', $curl);
        $this->assertStringContainsString('smtps://mail.example:465', $curl);

        $mail = (string) file_get_contents($this->dir . '/mail.eml');
        $this->assertStringContainsString('Subject: [work-tracker] Záloha zlyhala: test ' . service('clock')->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'), $mail);
        $this->assertStringContainsString('Záloha databázy sa nepodarila.', $mail);
        $this->assertStringContainsString('dump', $mail);
        $this->assertStringContainsString('Postup obnovy a kontroly: docs/ops/backup.md', $mail);
        $this->assertStringContainsString('pg_dump: simulated failure', $mail);
        $this->assertNoSecrets($mail);
        $this->assertNoSecrets($out);

        $this->assertStringNotContainsString('backup --tag', (string) @file_get_contents($this->dir . '/restic.log'));
        $this->assertSame([], glob($this->dir . '/stage/*.dump*'));
    }

    public function testResticBackupFailureStepIsRestic(): void
    {
        [$code, $out] = $this->runScript(['FAKE_RESTIC_FAIL' => 'backup']);

        $this->assertNotSame(0, $code);
        $this->assertMatchesRegularExpression('/krok:?\s*restic/iu', (string) file_get_contents($this->dir . '/mail.eml'));
        $this->assertSame([], glob($this->dir . '/stage/*.dump*'));
        $this->assertNoSecrets($out);
    }

    public function testForgetFailureStepIsForget(): void
    {
        [$code] = $this->runScript(['FAKE_RESTIC_FAIL' => 'forget']);

        $this->assertNotSame(0, $code);
        $this->assertMatchesRegularExpression('/krok:?\s*forget/iu', (string) file_get_contents($this->dir . '/mail.eml'));
    }

    public function testUninitializedRepositoryStepIsRepo(): void
    {
        [$code] = $this->runScript(['FAKE_RESTIC_FAIL' => 'config']);

        $this->assertNotSame(0, $code);
        $mail = (string) file_get_contents($this->dir . '/mail.eml');
        $this->assertMatchesRegularExpression('/krok:?\s*repo/iu', $mail);
        $this->assertStringContainsString('repozitár nie je inicializovaný', $mail);
        $this->assertStringNotContainsString('backup --tag', (string) @file_get_contents($this->dir . '/restic.log'));
    }

    public function testBadDocumentHashFailsManifestStep(): void
    {
        [$code] = $this->runScript(['FAKE_BAD_SHA' => '2']);

        $this->assertNotSame(0, $code);
        $this->assertMatchesRegularExpression('/krok:?\s*manifest/iu', (string) file_get_contents($this->dir . '/mail.eml'));
        $this->assertStringNotContainsString('backup --tag', (string) @file_get_contents($this->dir . '/restic.log'));
    }

    public function testFailedManifestQueryAbortsBackup(): void
    {
        [$code] = $this->runScript(['FAKE_PSQL_FAIL' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertMatchesRegularExpression('/krok:?\s*manifest/iu', (string) file_get_contents($this->dir . '/mail.eml'));
        $this->assertStringNotContainsString('backup --tag', (string) @file_get_contents($this->dir . '/restic.log'));
    }

    public function testDbChangeDuringDumpRepeatsDumpAndManifest(): void
    {
        [$code, $out] = $this->runScript(['FAKE_CHANGE_ONCE' => '1']);

        $this->assertSame(0, $code, $out);
        $this->assertSame(2, substr_count((string) file_get_contents($this->dir . '/docker.log'), 'pg_dump'));
        $this->assertStringContainsString('rows.invoices=5', (string) file_get_contents($this->dir . '/snap/manifest.txt'));
    }

    public function testDbChangingOnEveryAttemptFailsWithoutSnapshot(): void
    {
        [$code] = $this->runScript(['FAKE_CHANGE_ALWAYS' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertSame(3, substr_count((string) file_get_contents($this->dir . '/docker.log'), 'pg_dump'));
        $this->assertStringNotContainsString('backup --tag', (string) @file_get_contents($this->dir . '/restic.log'));
    }

    public function testEmptyHcUrlAndAlertToSendNothing(): void
    {
        [$code, $out] = $this->runScript([], ['HC_URL' => '', 'ALERT_TO' => '']);
        $this->assertSame(0, $code, $out);
        $this->assertFileDoesNotExist($this->dir . '/curl.log');

        [$code] = $this->runScript(['FAKE_DUMP_FAIL' => '1'], ['HC_URL' => '', 'ALERT_TO' => '']);
        $this->assertNotSame(0, $code);
        $this->assertFileDoesNotExist($this->dir . '/curl.log');
    }

    public function testFailureWithoutAlertToOnlyPingsFail(): void
    {
        [$code] = $this->runScript(['FAKE_DUMP_FAIL' => '1'], ['ALERT_TO' => '']);

        $this->assertNotSame(0, $code);
        $curl = (string) file_get_contents($this->dir . '/curl.log');
        $this->assertStringContainsString('SECRET-UUID/fail', (string) file_get_contents($this->dir . '/curl.log.cfg'));
        $this->assertStringNotContainsString('smtps://', $curl);
        $this->assertFileDoesNotExist($this->dir . '/mail.eml');
    }

    public function testReadableEnvFileIsRejected(): void
    {
        [$code, $out] = $this->runScript([], [], 0o644);

        $this->assertNotSame(0, $code);
        $this->assertMatchesRegularExpression('/prav|600|mode/iu', $out);
        $this->assertFileDoesNotExist($this->dir . '/restic.log');
        $this->assertNoSecrets($out);
    }

    public function testDbUserOverrideReachesDumpAndManifest(): void
    {
        [$code, $out] = $this->runScript([], ['BACKUP_DB_USER' => 'postgres', 'BACKUP_DB' => 'task_tracker']);

        $this->assertSame(0, $code, $out);
        $log = (string) file_get_contents($this->dir . '/docker.log');
        $this->assertStringContainsString('pg_dump -U postgres', $log);
        $this->assertStringContainsString('psql -U postgres -d task_tracker', $log);
        $this->assertStringNotContainsString('app_owner', $log);
    }

    public function testDefaultDbUserIsAppOwner(): void
    {
        [$code, $out] = $this->runScript();

        $this->assertSame(0, $code, $out);
        $log = (string) file_get_contents($this->dir . '/docker.log');
        $this->assertStringContainsString('pg_dump -U app_owner', $log);
        $this->assertStringContainsString('psql -U app_owner -d task_tracker', $log);
    }

    private function assertNoSecrets(string $text): void
    {
        $this->assertStringNotContainsString('SECRET-UUID', $text);
        $this->assertStringNotContainsString('RESTIC-PASS-TOPSECRET', $text);
        $this->assertStringNotContainsString('SMTP-PASS-TOPSECRET', $text);
    }

    /**
     * @param array<string, string> $extra   premenne procesu (FAKE_*)
     * @param array<string, string> $envFile prepisy hodnot v env subore
     *
     * @return array{0: int, 1: string} kod a stdout+stderr
     */
    private function runScript(array $extra = [], array $envFile = [], int $envMode = 0o600): array
    {
        $vals = $envFile + [
            'RESTIC_REPOSITORY'    => $this->dir . '/repo',
            'RESTIC_PASSWORD_FILE' => $this->dir . '/restic-pass',
            'HC_URL'               => 'https://hc.example/ping/SECRET-UUID',
            'ALERT_SMTP_URL'       => 'smtps://mail.example:465',
            'ALERT_SMTP_USER'      => 'noreply@example',
            'ALERT_SMTP_PASS_FILE' => $this->dir . '/smtp-pass',
            'ALERT_FROM'           => 'noreply@example',
            'ALERT_TO'             => 'owner@example',
            'BACKUP_ENV_NAME'      => 'test',
            'STACK_DIR'            => $this->dir . '/stack',
            'STAGE_DIR'            => $this->dir . '/stage',
            'BACKUP_COMPOSE_FILE'  => '',
        ];
        $envPath = $this->dir . '/backup.env';
        $body    = '';

        foreach ($vals as $k => $v) {
            $body .= $k . "='" . $v . "'\n";
        }
        file_put_contents($envPath, $body);
        chmod($envPath, $envMode);

        foreach (['docker.log', 'docker.log.dumped', 'docker.log.chg', 'restic.log', 'curl.log', 'curl.log.cfg', 'mail.eml'] as $f) {
            @unlink($this->dir . '/' . $f);
        }

        $env = [
            'PATH'            => $this->dir . '/bin:/usr/local/bin:/usr/bin:/bin',
            'TZ'              => 'UTC',
            'BACKUP_ENV_FILE' => $envPath,
            'FAKE_LOG'        => $this->dir . '/docker.log',
            'FAKE_RESTIC_LOG' => $this->dir . '/restic.log',
            'FAKE_CURL_LOG'   => $this->dir . '/curl.log',
            'FAKE_MAIL'       => $this->dir . '/mail.eml',
            'FAKE_SNAP'       => $this->dir . '/snap',
        ] + $extra;

        $proc = proc_open(['sh', $this->dir . '/scripts/backup-restic.sh'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);

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
