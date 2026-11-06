<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * D-07, D-09: scripts/install-backup.sh v rezime DRY_RUN iba vypisuje plan, nic nemeni.
 *
 * @internal
 */
#[Group('unit')]
final class InstallBackupScriptTest extends CIUnitTestCase
{
    private const SCRIPT = ROOTPATH . 'scripts/install-backup.sh';

    /**
     * @return array{0: string, 1: int}
     */
    private function runScript(string $env): array
    {
        $out  = [];
        $code = 0;
        exec($env . ' sh ' . escapeshellarg(self::SCRIPT) . ' 2>&1', $out, $code);

        return [implode("\n", $out), $code];
    }

    public function testDryRunPlansEveryStep(): void
    {
        [$out, $code] = $this->runScript('DRY_RUN=1 RESTIC_ARCH=amd64');

        $this->assertSame(0, $code, $out);

        foreach ([
            'PLAN:',
            'restic_0.19.1_linux_amd64.bz2',
            'SHA256SUMS',
            'sha256sum -c',
            'install -d -m 0700 /var/backups/restic/work-tracker',
            'install -m 0600 /root/.config/work-tracker-backup.env',
            'visudo',
            'rrsync -ro /var/backups/restic/work-tracker',
            'backup-restic.sh >> /var/log/work-tracker-backup.log',
            'restore-test.sh >> /var/log/work-tracker-restore-test.log',
            'archive-yearly.sh >> /var/log/work-tracker-archive.log',
            'RESTIC_REPOSITORY=',
            'RESTIC_PASSWORD_FILE=',
            'HC_URL=',
            'HC_ARCHIVE_URL=',
            'HC_RESTORE_URL=',
            'ALERT_SMTP_URL=',
            'ALERT_TO=',
            'BACKUP_ENV_NAME=prod',
            'STACK_DIR=/opt/work-tracker',
            'BACKUP_DB_USER=app_owner',
            'BACKUP_DB=task_tracker',
        ] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }

        foreach (['backup.sh', 'backup-restic.sh', 'lib-restic-policy.sh', 'lib-backup-notify.sh', 'archive-yearly.sh', 'restore-test.sh'] as $s) {
            $this->assertStringContainsString('install -o root -g root -m 0755 ', $out);
            $this->assertStringContainsString('/usr/local/lib/work-tracker-backup/scripts/' . $s, $out);
        }
    }

    public function testNeverTouchesAppEnvNorEditsInPlace(): void
    {
        [$out] = $this->runScript('DRY_RUN=1 RESTIC_ARCH=amd64');
        $code  = '';

        foreach (explode("\n", (string) file_get_contents(self::SCRIPT)) as $line) {
            if (! str_starts_with(ltrim($line), '#')) {
                $code .= $line . "\n";
            }
        }

        foreach (['app.env', 'migrate.env', 'sed -i', 'crontab -'] as $bad) {
            $this->assertStringNotContainsString($bad, $out);
            $this->assertStringNotContainsString($bad, $code);
        }
    }

    public function testUnsupportedArchitectureFails(): void
    {
        [$out, $code] = $this->runScript('DRY_RUN=1 RESTIC_ARCH=mips');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('architektura', $out);
    }

    public function testRequiresRootWithoutDryRun(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('beziace ako root');
        }

        [$out, $code] = $this->runScript('RESTIC_ARCH=amd64');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('root', $out);
    }
}
