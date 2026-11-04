<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * D-07, D-10: retencna politika z scripts/lib-restic-policy.sh overena realnym restic v docasnom repozitari.
 *
 * @internal
 */
#[Group('unit')]
final class ResticRetentionTest extends CIUnitTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        exec('command -v restic > /dev/null 2>&1', $o, $rc);

        if ($rc !== 0) {
            $this->markTestSkipped('restic chýba v obraze, rebuild dev obrazu');
        }

        $this->dir = sys_get_temp_dir() . '/restic-retention-' . bin2hex(random_bytes(6));

        foreach (['a', 'b', 'archive'] as $sub) {
            mkdir($this->dir . '/' . $sub, 0o700, true);
            file_put_contents($this->dir . '/' . $sub . '/f.txt', $sub);
        }
        file_put_contents($this->dir . '/pass', 'retention-test-pass');
        chmod($this->dir . '/pass', 0o600);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    public function testKeepsFourteenDailiesAndArchiveSnapshot(): void
    {
        $repo = $this->dir . '/repo';
        $this->restic($repo, ['init']);

        $day = strtotime('2026-09-01 12:00:00 UTC');

        for ($i = 0; $i < 40; $i++) {
            $this->restic($repo, ['backup', '--no-scan', '--tag', 'db', '--host', 'work-tracker', '--time', gmdate('Y-m-d H:i:s', $day + $i * 86400), $this->dir . '/a']);
        }
        $this->restic($repo, ['backup', '--tag', 'archive', '--host', 'work-tracker', '--time', '2024-01-05 04:00:00', $this->dir . '/archive']);

        $this->restic($repo, array_merge(['forget'], $this->policy()));

        $snaps   = json_decode($this->restic($repo, ['snapshots', '--json']), true, 512, JSON_THROW_ON_ERROR);
        $archive = array_filter($snaps, static fn (array $s): bool => in_array('archive', $s['tags'] ?? [], true));
        $db      = array_filter($snaps, static fn (array $s): bool => in_array('db', $s['tags'] ?? [], true));

        $this->assertCount(1, $archive, 'archive snapshot starší ako rok sa nesmie zmazať');
        $this->assertLessThanOrEqual(14 + 8 + 12, count($db));
        $this->assertLessThan(40, count($db));

        $dates = array_map(static fn (array $s): string => substr((string) $s['time'], 0, 10), $db);

        for ($i = 26; $i < 40; $i++) { // 2026-09-27 az 2026-10-10
            $this->assertContains(gmdate('Y-m-d', $day + $i * 86400), $dates);
        }
    }

    public function testDifferentPathsFormSeparateGroupsWithoutGroupBy(): void
    {
        $repo = $this->dir . '/repo2';
        $this->restic($repo, ['init']);
        $this->restic($repo, ['backup', '--tag', 'db', '--host', 'work-tracker', '--time', '2026-10-01 03:00:00', $this->dir . '/a']);
        $this->restic($repo, ['backup', '--tag', 'db', '--host', 'work-tracker', '--time', '2026-10-02 03:00:00', $this->dir . '/b']);

        // Predvolene skupiny host,paths: kazda cesta ma vlastnu skupinu, takze keep-last 1 nezmaze nic.
        $this->restic($repo, ['forget', '--keep-last', '1']);
        $this->assertCount(2, json_decode($this->restic($repo, ['snapshots', '--json']), true, 512, JSON_THROW_ON_ERROR));

        // S --group-by host,tags je jedna skupina, ostane iba najnovsi.
        $this->restic($repo, ['forget', '--group-by', 'host,tags', '--keep-last', '1']);
        $this->assertCount(1, json_decode($this->restic($repo, ['snapshots', '--json']), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    private function policy(): array
    {
        $line = (string) shell_exec('cd ' . escapeshellarg(ROOTPATH) . " && sh -c '. scripts/lib-restic-policy.sh; printf %s \"\$RESTIC_FORGET_POLICY\"'");
        $this->assertStringContainsString('--keep-tag archive', $line);

        return explode(' ', $line);
    }

    /**
     * @param list<string> $args
     */
    private function restic(string $repo, array $args): string
    {
        $env  = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'RESTIC_REPOSITORY' => $repo, 'RESTIC_PASSWORD_FILE' => $this->dir . '/pass', 'RESTIC_CACHE_DIR' => $this->dir . '/cache', 'HOME' => $this->dir];
        $proc = proc_open(array_merge(['restic', '-q'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);

        if (! is_resource($proc)) {
            throw new RuntimeException('proc_open failed');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, 'restic ' . implode(' ', $args) . ': ' . $err);

        return $out;
    }
}
