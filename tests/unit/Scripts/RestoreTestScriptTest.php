<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * D-15, NFR-05: scripts/restore-test.sh bezi s falosnym docker, restic a curl na PATH.
 *
 * @internal
 */
#[Group('unit')]
final class RestoreTestScriptTest extends CIUnitTestCase
{
    private const FAKE_DOCKER = <<<'SH'
        #!/bin/sh
        printf '%s | pwd=%s\n' "$*" "$(pwd)" >> "$FAKE_LOG"
        case "$*" in
          *psql*)
            case "$*" in
              *"FROM invoices")
                if [ -n "${FAKE_ROWS_INVOICES-}" ]; then echo "$FAKE_ROWS_INVOICES"; else echo 5; fi ;;
              *"number FROM invoices"*) echo "2026-0007" ;;
              *"md5(string_agg"*) echo "digestabc" ;;
              *"encode(sha256(content)"*) echo "0" ;;
              *) echo "5" ;;
            esac
            ;;
          *pg_restore*) cat > /dev/null ;;
        esac
        exit 0
        SH;

    private const FAKE_RESTIC = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_RESTIC_LOG"
        if [ "$1" = restore ]; then
          prev=
          for a in "$@"; do
            [ "$prev" != --target ] || target=$a
            prev=$a
          done
          mkdir -p "$target/stage"
          cp "$FAKE_SNAP"/* "$target/stage"/
          echo "restoring <Snapshot abc12345 of [/stage] at 2026-11-01> to $target"
        fi
        exit 0
        SH;

    private const FAKE_CURL = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_CURL_LOG"
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
        $this->dir = sys_get_temp_dir() . '/restore-script-' . bin2hex(random_bytes(6));

        foreach (['scripts', 'bin', 'snap', 'stack', 'tmp'] as $sub) {
            if (! mkdir($this->dir . '/' . $sub, 0o700, true)) {
                throw new RuntimeException('mkdir failed');
            }
        }

        foreach (['lib-restic-policy.sh', 'lib-backup-notify.sh', 'restore-test.sh'] as $f) {
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

        $this->writeSnapshot('PGDMP-fake-dump');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
        parent::tearDown();
    }

    public function testMatchingRestoreSucceedsAndCleansUp(): void
    {
        [$code, $out] = $this->runScript();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('OK: obnova abc12345 restore_check_', $out);
        $this->assertStringContainsString('rows.invoices=5 last_invoice_number=2026-0007', $out);

        $log = (string) file_get_contents($this->dir . '/docker.log');
        $this->assertMatchesRegularExpression('/createdb -U postgres -O app_owner (restore_check_\S+)/', $log);
        preg_match('/createdb -U postgres -O app_owner (restore_check_\S+)/', $log, $m);
        $db = $m[1];
        $this->assertStringContainsString('pg_restore -U app_owner --no-owner --exit-on-error -d ' . $db, $log);
        $this->assertMatchesRegularExpression('/dropdb -U postgres --if-exists ' . preg_quote($db, '/') . '/', $log);
        $this->assertStringContainsString('restore latest --tag db --host work-tracker --target', (string) file_get_contents($this->dir . '/restic.log'));
        $this->assertSame([], glob($this->dir . '/tmp/*'));
        $this->assertStringNotContainsString('/fail', (string) @file_get_contents($this->dir . '/curl.log'));
        $this->assertNoSecrets($out);
    }

    public function testRowMismatchFailsReportsAndStillDropsDb(): void
    {
        [$code, $out] = $this->runScript(['FAKE_ROWS_INVOICES' => '9']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('rows.invoices', $out);
        $this->assertStringContainsString('9', $out);
        $this->assertStringContainsString('5', $out);

        $mail = (string) file_get_contents($this->dir . '/mail.eml');
        $this->assertMatchesRegularExpression('/Subject: \[work-tracker\] Test obnovy zlyhal: test \d{4}-\d{2}-\d{2}/', $mail);
        $this->assertStringContainsString('hc.example/ping/SECRET-UUID/fail', (string) file_get_contents($this->dir . '/curl.log'));
        $this->assertStringContainsString('dropdb', (string) file_get_contents($this->dir . '/docker.log'));
        $this->assertSame([], glob($this->dir . '/tmp/*'));
        $this->assertNoSecrets($out);
        $this->assertNoSecrets($mail);
    }

    public function testBadDumpChecksumStopsBeforeCreatedb(): void
    {
        file_put_contents($this->dir . '/snap/task_tracker.dump', 'TAMPERED');
        [$code] = $this->runScript();

        $this->assertNotSame(0, $code);
        $this->assertMatchesRegularExpression('/krok:?\s*checksum/iu', (string) file_get_contents($this->dir . '/mail.eml'));
        $this->assertStringNotContainsString('createdb', (string) @file_get_contents($this->dir . '/docker.log'));
        $this->assertSame([], glob($this->dir . '/tmp/*'));
    }

    public function testLocalModeNeedsNoEnvFileAndSendsNothing(): void
    {
        [$code, $out] = $this->runScript([], [
            'RESTORE_LOCAL'        => '1',
            'RESTIC_REPOSITORY'    => $this->dir . '/repo',
            'RESTIC_PASSWORD_FILE' => $this->dir . '/restic-pass',
            'BACKUP_ENV_FILE'      => $this->dir . '/does-not-exist.env',
        ]);

        $this->assertSame(0, $code, $out);
        $this->assertFileDoesNotExist($this->dir . '/curl.log');
        $this->assertStringContainsString('pwd=' . $this->dir, (string) file_get_contents($this->dir . '/docker.log'));
    }

    public function testLocalKeepRecreatesTargetAndKeepsIt(): void
    {
        [$code, $out] = $this->runScript([], [
            'RESTORE_LOCAL'        => '1',
            'RESTORE_KEEP'         => '1',
            'RESTORE_TARGET_DB'    => 'task_tracker',
            'RESTIC_REPOSITORY'    => $this->dir . '/repo',
            'RESTIC_PASSWORD_FILE' => $this->dir . '/restic-pass',
        ]);

        $this->assertSame(0, $code, $out);
        $log = (string) file_get_contents($this->dir . '/docker.log');
        $this->assertStringContainsString('dropdb -U postgres --if-exists task_tracker', $log);
        $this->assertStringContainsString('createdb -U postgres -O app_owner task_tracker', $log);
        $this->assertSame(1, substr_count($log, 'dropdb'));
        $this->assertLessThan(strpos($log, 'createdb'), strpos($log, 'dropdb'));
    }

    public function testServerModeRefusesProductionDbName(): void
    {
        [$code] = $this->runScript([], ['RESTORE_TARGET_DB' => 'task_tracker', 'RESTORE_KEEP' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertFileDoesNotExist($this->dir . '/docker.log');
    }

    public function testInvalidDbNameRejectedWithoutDocker(): void
    {
        [$code] = $this->runScript([], [
            'RESTORE_LOCAL'        => '1',
            'RESTORE_TARGET_DB'    => 'x;drop',
            'RESTIC_REPOSITORY'    => $this->dir . '/repo',
            'RESTIC_PASSWORD_FILE' => $this->dir . '/restic-pass',
        ]);

        $this->assertNotSame(0, $code);
        $this->assertFileDoesNotExist($this->dir . '/docker.log');
    }

    private function writeSnapshot(string $dump): void
    {
        file_put_contents($this->dir . '/snap/task_tracker.dump', $dump);
        file_put_contents($this->dir . '/snap/task_tracker.dump.sha256', hash('sha256', $dump) . "  task_tracker.dump\n");
        $man = "created_at=2026-11-01T03:10:00Z\nenv=prod\n";

        foreach (['invoices', 'invoice_lines', 'clients'] as $t) {
            $man .= 'rows.' . $t . "=5\n";
        }
        $man .= "last_invoice_number=2026-0007\ndocuments_sha256_digest=digestabc\ndocuments_bad_sha256=0\ndump_sha256=" . hash('sha256', $dump) . "\n";
        file_put_contents($this->dir . '/snap/manifest.txt', $man);
    }

    private function assertNoSecrets(string $text): void
    {
        $this->assertStringNotContainsString('SECRET-UUID', $text);
        $this->assertStringNotContainsString('RESTIC-PASS-TOPSECRET', $text);
        $this->assertStringNotContainsString('SMTP-PASS-TOPSECRET', $text);
    }

    /**
     * @param array<string, string> $extra    premenne procesu (FAKE_*, RESTORE_*)
     * @param array<string, string> $override prepisy premennych procesu (a pre server rezim hodnoty v env subore)
     *
     * @return array{0: int, 1: string}
     */
    private function runScript(array $extra = [], array $override = []): array
    {
        $local   = ($override['RESTORE_LOCAL'] ?? '') === '1';
        $envPath = $override['BACKUP_ENV_FILE'] ?? $this->dir . '/backup.env';

        if (! $local) {
            $vals = [
                'RESTIC_REPOSITORY'    => $this->dir . '/repo',
                'RESTIC_PASSWORD_FILE' => $this->dir . '/restic-pass',
                'HC_RESTORE_URL'       => 'https://hc.example/ping/SECRET-UUID',
                'ALERT_SMTP_URL'       => 'smtps://mail.example:465',
                'ALERT_SMTP_USER'      => 'noreply@example',
                'ALERT_SMTP_PASS_FILE' => $this->dir . '/smtp-pass',
                'ALERT_FROM'           => 'noreply@example',
                'ALERT_TO'             => 'owner@example',
                'BACKUP_ENV_NAME'      => 'test',
            ];
            $body = '';

            foreach ($vals as $k => $v) {
                $body .= $k . "='" . $v . "'\n";
            }
            file_put_contents($envPath, $body);
            chmod($envPath, 0o600);
        }

        foreach (['docker.log', 'restic.log', 'curl.log', 'mail.eml'] as $f) {
            @unlink($this->dir . '/' . $f);
        }

        $env = [
            'PATH'            => $this->dir . '/bin:/usr/local/bin:/usr/bin:/bin',
            'TZ'              => 'UTC',
            'TMPDIR'          => $this->dir . '/tmp',
            'BACKUP_ENV_FILE' => $envPath,
            'STACK_DIR'       => $this->dir . '/stack',
            'FAKE_LOG'        => $this->dir . '/docker.log',
            'FAKE_RESTIC_LOG' => $this->dir . '/restic.log',
            'FAKE_CURL_LOG'   => $this->dir . '/curl.log',
            'FAKE_MAIL'       => $this->dir . '/mail.eml',
            'FAKE_SNAP'       => $this->dir . '/snap',
        ] + $extra;

        foreach (['RESTORE_LOCAL', 'RESTORE_KEEP', 'RESTORE_TARGET_DB', 'RESTIC_REPOSITORY', 'RESTIC_PASSWORD_FILE'] as $k) {
            if (isset($override[$k])) {
                $env[$k] = $override[$k];
            }
        }

        $proc = proc_open(['sh', $this->dir . '/scripts/restore-test.sh'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);

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
