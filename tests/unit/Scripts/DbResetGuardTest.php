<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * CR-02: ochrana `make reset` (scripts/db-reset.sh) pred DROP DATABASE mimo vyvoja.
 * Skript bezi v docasnom adresari s falosnym `docker` na PATH, ktory len zapisuje
 * volania. Skutocna DB sa nikdy nedotkne.
 *
 * @internal
 */
#[Group('unit')]
final class DbResetGuardTest extends CIUnitTestCase
{
    private const FAKE_DOCKER = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$*" >> "$FAKE_LOG"
        case "$*" in
          *CI_ENVIRONMENT-__unset__*)
            [ -z "${FAKE_PHP_DOWN-}" ] || exit 1
            printf '%s' "${FAKE_PHP_ENV-__unset__}"
            ;;
        esac
        exit 0
        SH;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/dbreset-guard-' . bin2hex(random_bytes(6));

        foreach (['scripts', 'bin'] as $sub) {
            if (! mkdir($this->dir . '/' . $sub, 0o700, true)) {
                throw new RuntimeException('mkdir failed');
            }
        }
        copy(ROOTPATH . 'scripts/db-reset.sh', $this->dir . '/scripts/db-reset.sh');
        copy(ROOTPATH . 'scripts/lib-env-guard.sh', $this->dir . '/scripts/lib-env-guard.sh');
        file_put_contents($this->dir . '/bin/docker', self::FAKE_DOCKER . "\n");
        chmod($this->dir . '/bin/docker', 0o700);
    }

    protected function tearDown(): void
    {
        foreach (['.env', 'docker.log', 'scripts/db-reset.sh', 'scripts/lib-env-guard.sh', 'bin/docker'] as $f) {
            if (is_file($this->dir . '/' . $f)) {
                unlink($this->dir . '/' . $f);
            }
        }

        foreach (['scripts', 'bin', ''] as $d) {
            if (is_dir($this->dir . '/' . $d)) {
                rmdir($this->dir . '/' . $d);
            }
        }
        parent::tearDown();
    }

    #[DataProvider('provideGuard')]
    public function testGuard(?string $envFile, ?string $processEnv, ?string $phpEnv, bool $allowed): void
    {
        if ($envFile !== null) {
            file_put_contents($this->dir . '/.env', $envFile);
        }

        $env = ['PATH' => $this->dir . '/bin:/usr/local/bin:/usr/bin:/bin', 'FAKE_LOG' => $this->dir . '/docker.log'];
        if ($processEnv !== null) {
            $env['CI_ENVIRONMENT'] = $processEnv;
        }
        if ($phpEnv !== null) {
            $env['FAKE_PHP_ENV'] = $phpEnv;
        }

        [$code, $stderr] = $this->runReset($env);
        $log             = is_file($this->dir . '/docker.log') ? (string) file_get_contents($this->dir . '/docker.log') : '';

        if ($allowed) {
            $this->assertSame(0, $code, $stderr);
            $this->assertStringContainsString('DROP DATABASE', $log);
        } else {
            $this->assertNotSame(0, $code);
            $this->assertStringContainsString('REFUSED', $stderr);
            $this->assertStringNotContainsString('DROP DATABASE', $log);
        }
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string|null, 3: bool}>
     */
    public static function provideGuard(): iterable
    {
        return [
            'development'                => ["CI_ENVIRONMENT = development\n", null, null, true],
            'testing'                    => ["CI_ENVIRONMENT = testing\n", null, null, true],
            'development CRLF'           => ["app.x = 1\r\nCI_ENVIRONMENT = development\r\n", null, null, true],
            'development single quotes'  => ["CI_ENVIRONMENT = 'development'\n", null, null, true],
            'development double quotes'  => ["CI_ENVIRONMENT = \"development\"\n", null, null, true],
            'export prefix, no spaces'   => ["export CI_ENVIRONMENT=development\n", null, null, true],
            'inline comment'             => ["CI_ENVIRONMENT = development # dev\n", null, null, true],
            'production'                 => ["CI_ENVIRONMENT = production\n", null, null, false],
            'production single quotes'   => ["CI_ENVIRONMENT = 'production'\n", null, null, false],
            'production double quotes'   => ["CI_ENVIRONMENT = \"production\"\n", null, null, false],
            'production CRLF'            => ["CI_ENVIRONMENT = production\r\n", null, null, false],
            'production tab'             => ["CI_ENVIRONMENT\t=\tproduction\n", null, null, false],
            'unknown value'              => ["CI_ENVIRONMENT = staging\n", null, null, false],
            'wrong case'                 => ["CI_ENVIRONMENT = Development\n", null, null, false],
            'empty value'                => ["CI_ENVIRONMENT =\n", null, null, false],
            'key missing'                => ["app.baseURL = 'https://x/'\n", null, null, false],
            'commented dev, real prod'   => ["# CI_ENVIRONMENT = development\nCI_ENVIRONMENT = production\n", null, null, false],
            'first occurrence wins'      => ["CI_ENVIRONMENT = production\nCI_ENVIRONMENT = development\n", null, null, false],
            'no .env'                    => [null, null, null, false],
            'process env production'     => ["CI_ENVIRONMENT = development\n", 'production', null, false],
            'process env dev, .env prod' => ["CI_ENVIRONMENT = production\n", 'development', null, false],
            'process env testing'        => ["CI_ENVIRONMENT = development\n", 'testing', null, true],
            'php container production'   => ["CI_ENVIRONMENT = development\n", null, 'production', false],
            'php container quoted prod'  => ["CI_ENVIRONMENT = development\n", null, "'production'\r", false],
            'php container development'  => ["CI_ENVIRONMENT = development\n", null, 'development', true],
            // proc_open zahodi prazdnu hodnotu, preto medzera + CR (po normalizacii prazdne).
            'php container blank' => ["CI_ENVIRONMENT = development\n", null, " \r", false],
        ];
    }

    public function testRefusesWhenPhpContainerUnavailable(): void
    {
        file_put_contents($this->dir . '/.env', "CI_ENVIRONMENT = development\n");

        [$code, $stderr] = $this->runReset([
            'PATH'          => $this->dir . '/bin:/usr/local/bin:/usr/bin:/bin',
            'FAKE_LOG'      => $this->dir . '/docker.log',
            'FAKE_PHP_DOWN' => '1',
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('REFUSED', $stderr);
        $this->assertStringNotContainsString('DROP DATABASE', (string) file_get_contents($this->dir . '/docker.log'));
    }

    public function testStagingModeKeepsGuardAndRunsStagingSeedInsteadOfDevSeeder(): void
    {
        file_put_contents($this->dir . '/.env', "CI_ENVIRONMENT = development\n");
        $env = ['PATH' => $this->dir . '/bin:/usr/local/bin:/usr/bin:/bin', 'FAKE_LOG' => $this->dir . '/docker.log'];

        [$code, $stderr] = $this->runReset($env, ['--staging']);
        $log             = (string) file_get_contents($this->dir . '/docker.log');

        $this->assertSame(0, $code, $stderr);
        $this->assertStringContainsString('DROP DATABASE', $log);
        $this->assertStringContainsString('-e staging.seed=true php php spark staging:seed', $log);
        $this->assertStringNotContainsString('DevSeeder', $log);
    }

    public function testStagingModeIsRefusedOutsideDevelopment(): void
    {
        file_put_contents($this->dir . '/.env', "CI_ENVIRONMENT = production\n");

        [$code, $stderr] = $this->runReset(['PATH' => $this->dir . '/bin:/usr/local/bin:/usr/bin:/bin', 'FAKE_LOG' => $this->dir . '/docker.log'], ['--staging']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('REFUSED', $stderr);
        $this->assertStringNotContainsString('staging:seed', is_file($this->dir . '/docker.log') ? (string) file_get_contents($this->dir . '/docker.log') : '');
    }

    public function testUnknownArgumentIsRejectedBeforeAnyDockerCall(): void
    {
        file_put_contents($this->dir . '/.env', "CI_ENVIRONMENT = development\n");

        [$code] = $this->runReset(['PATH' => $this->dir . '/bin:/usr/local/bin:/usr/bin:/bin', 'FAKE_LOG' => $this->dir . '/docker.log'], ['--prod']);

        $this->assertNotSame(0, $code);
        $this->assertFileDoesNotExist($this->dir . '/docker.log');
    }

    public function testGuardRunsBeforeDrop(): void
    {
        $script = (string) file_get_contents(ROOTPATH . 'scripts/db-reset.sh');
        $guard  = strpos($script, 'require_dev_env .env');
        $drop   = strpos($script, 'DROP DATABASE');

        $this->assertIsInt($guard);
        $this->assertIsInt($drop);
        $this->assertLessThan($drop, $guard);
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $args
     *
     * @return array{0: int, 1: string}
     */
    private function runReset(array $env, array $args = []): array
    {
        $proc = proc_open(['sh', 'scripts/db-reset.sh', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);
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
