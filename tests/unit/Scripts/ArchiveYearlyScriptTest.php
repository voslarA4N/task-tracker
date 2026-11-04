<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * D-10, D-09: scripts/archive-yearly.sh bezi v docasnom adresari s falosnym docker, restic a curl na PATH.
 *
 * @internal
 */
#[Group('unit')]
final class ArchiveYearlyScriptTest extends CIUnitTestCase
{
    private const FAKE_DOCKER = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_LOG"
        for last in "$@"; do :; done
        case "$*" in
          *"spark archive:year"*)
            [ -z "${FAKE_SPARK_FAIL-}" ] || { echo "Archív sa nevytvoril: simulated" >&2; exit 1; }
            echo "Archív roka $last: 3 dokladov, 5 PDF, 1 kB"
            echo "PATH=/var/www/html/writable/archive/$last/work-tracker-archiv-$last.zip"
            ;;
          *" cp "*|"compose cp "*)
            src=${3#php:}
            base=$(basename "$src")
            year=$(basename "$(dirname "$src")")
            case "$base" in
              *.sha256)
                if [ -n "${FAKE_BAD_SHA-}" ]; then
                  printf '%s  %s\n' 0000000000000000000000000000000000000000000000000000000000000000 "${base%.sha256}" > "$4/$base"
                else
                  printf '%s  %s\n' "$(printf 'FAKEZIP-%s' "$year" | sha256sum | cut -d' ' -f1)" "${base%.sha256}" > "$4/$base"
                fi
                ;;
              *) printf 'FAKEZIP-%s' "$year" > "$4/$base" ;;
            esac
            ;;
        esac
        exit 0
        SH;
    private const FAKE_RESTIC = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_RESTIC_LOG"
        case "$1" in
          backup)
            [ "${FAKE_RESTIC_FAIL-}" != backup ] || { echo "restic backup simulated failure" >&2; exit 1; }
            for last in "$@"; do :; done
            rm -rf "$FAKE_SNAP"; mkdir -p "$FAKE_SNAP"; cp "$last"/* "$FAKE_SNAP"/
            ;;
        esac
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
        $this->dir = sys_get_temp_dir() . '/archive-script-' . bin2hex(random_bytes(6));

        foreach (['scripts', 'bin', 'stage', 'stack'] as $sub) {
            if (! mkdir($this->dir . '/' . $sub, 0o700, true)) {
                throw new RuntimeException('mkdir failed');
            }
        }

        foreach (['lib-restic-policy.sh', 'lib-backup-notify.sh', 'archive-yearly.sh'] as $f) {
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

    public function testSuccessStoresArchiveSnapshotAndCleansUp(): void
    {
        [$code, $out] = $this->runScript(['2026']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('OK: archive 2026', $out);

        $restic = (string) file_get_contents($this->dir . '/restic.log');
        $this->assertStringContainsString('backup --tag archive --tag year-2026 --host work-tracker ' . $this->dir . '/stage/2026', $restic);
        $this->assertStringNotContainsString('forget', $restic);

        $this->assertSame('FAKEZIP-2026', file_get_contents($this->dir . '/snap/work-tracker-archiv-2026.zip'));
        $this->assertFileExists($this->dir . '/snap/work-tracker-archiv-2026.zip.sha256');

        $this->assertSame([], glob($this->dir . '/stage/*'));
        $docker = (string) file_get_contents($this->dir . '/docker.log');
        $this->assertStringContainsString('exec -T php php spark archive:year 2026', $docker);
        $this->assertStringContainsString('rm -f /var/www/html/writable/archive/2026/work-tracker-archiv-2026.zip', $docker);

        $curl = (string) file_get_contents($this->dir . '/curl.log');
        $this->assertStringContainsString('https://hc.example/archive/SECRET-UUID', $curl);
        $this->assertStringNotContainsString('/fail', $curl);
        $this->assertNoSecrets($out);
    }

    public function testDefaultsToPreviousYear(): void
    {
        [$code, $out] = $this->runScript([], ['ARCHIVE_YEAR_NOW' => '2027']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('OK: archive 2026', $out);
        $this->assertStringContainsString('year-2026', (string) file_get_contents($this->dir . '/restic.log'));
    }

    public function testChecksumMismatchFailsVerifyAndSkipsRestic(): void
    {
        [$code, $out] = $this->runScript(['2026'], ['FAKE_BAD_SHA' => '1']);

        $this->assertNotSame(0, $code);
        $mail = (string) file_get_contents($this->dir . '/mail.eml');
        $this->assertMatchesRegularExpression('/Subject: \[work-tracker\] Archív zlyhal: test \d{4}-\d{2}-\d{2}/u', $mail);
        $this->assertMatchesRegularExpression('/krok:?\s*verify/iu', $mail);
        $this->assertFileDoesNotExist($this->dir . '/restic.log');
        $this->assertSame([], glob($this->dir . '/stage/*'));
        $this->assertStringContainsString('https://hc.example/archive/SECRET-UUID/fail', (string) file_get_contents($this->dir . '/curl.log'));
        $this->assertNoSecrets($mail);
        $this->assertNoSecrets($out);
    }

    public function testSparkFailureFailsSparkStep(): void
    {
        [$code] = $this->runScript(['2026'], ['FAKE_SPARK_FAIL' => '1']);

        $this->assertNotSame(0, $code);
        $this->assertMatchesRegularExpression('/krok:?\s*spark/iu', (string) file_get_contents($this->dir . '/mail.eml'));
        $this->assertFileDoesNotExist($this->dir . '/restic.log');
    }

    public function testResticFailureKeepsContainerZipAndAlerts(): void
    {
        [$code] = $this->runScript(['2026'], ['FAKE_RESTIC_FAIL' => 'backup']);

        $this->assertNotSame(0, $code);
        $this->assertMatchesRegularExpression('/krok:?\s*restic/iu', (string) file_get_contents($this->dir . '/mail.eml'));
        $this->assertStringNotContainsString('rm -f', (string) file_get_contents($this->dir . '/docker.log'));
        $this->assertSame([], glob($this->dir . '/stage/*'));
    }

    public function testInvalidYearFailsWithoutDocker(): void
    {
        [$code, $out] = $this->runScript(['20x6']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('neplatný rok', $out);
        $this->assertFileDoesNotExist($this->dir . '/docker.log');
        $this->assertFileDoesNotExist($this->dir . '/restic.log');
    }

    public function testScriptNeverForgetsSnapshots(): void
    {
        $lines = array_filter(
            explode("\n", (string) file_get_contents(ROOTPATH . 'scripts/archive-yearly.sh')),
            static fn (string $l): bool => ! str_starts_with(ltrim($l), '#'),
        );

        $this->assertStringNotContainsString('forget', implode("\n", $lines));
    }

    private function assertNoSecrets(string $text): void
    {
        $this->assertStringNotContainsString('SECRET-UUID', $text);
        $this->assertStringNotContainsString('RESTIC-PASS-TOPSECRET', $text);
        $this->assertStringNotContainsString('SMTP-PASS-TOPSECRET', $text);
    }

    /**
     * @param list<string>          $args  argumenty skriptu
     * @param array<string, string> $extra premenne procesu (FAKE_*, ARCHIVE_YEAR_NOW)
     *
     * @return array{0: int, 1: string} kod a stdout+stderr
     */
    private function runScript(array $args = [], array $extra = []): array
    {
        $vals = [
            'RESTIC_REPOSITORY'    => $this->dir . '/repo',
            'RESTIC_PASSWORD_FILE' => $this->dir . '/restic-pass',
            'HC_ARCHIVE_URL'       => 'https://hc.example/archive/SECRET-UUID',
            'ALERT_SMTP_URL'       => 'smtps://mail.example:465',
            'ALERT_SMTP_USER'      => 'noreply@example',
            'ALERT_SMTP_PASS_FILE' => $this->dir . '/smtp-pass',
            'ALERT_FROM'           => 'noreply@example',
            'ALERT_TO'             => 'owner@example',
            'BACKUP_ENV_NAME'      => 'test',
            'STACK_DIR'            => $this->dir . '/stack',
            'ARCHIVE_STAGE'        => $this->dir . '/stage',
            'BACKUP_COMPOSE_FILE'  => '',
        ];
        $envPath = $this->dir . '/backup.env';
        $body    = '';

        foreach ($vals as $k => $v) {
            $body .= $k . "='" . $v . "'\n";
        }
        file_put_contents($envPath, $body);
        chmod($envPath, 0o600);

        foreach (['docker.log', 'restic.log', 'curl.log', 'mail.eml'] as $f) {
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

        $proc = proc_open(['sh', $this->dir . '/scripts/archive-yearly.sh', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);

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
