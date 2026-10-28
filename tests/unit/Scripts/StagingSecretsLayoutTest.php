<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * WR-06: php-fpm na serveri (prod aj staging) nevidi heslo superusera ani app_owner; DB dostava hesla
 * iba ako Docker secrets (*_FILE), nie cez premenne prostredia v compose.
 *
 * @internal
 */
#[Group('unit')]
final class StagingSecretsLayoutTest extends CIUnitTestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function testPhpServiceMountsOnlyAppEnv(): void
    {
        $php = $this->service('php');

        $this->assertStringContainsString('./app.env:/var/www/html/.env:ro', $php);
        $this->assertStringNotContainsString('./.env:', $php);
        $this->assertStringNotContainsString('migrate.env', $php);
        $this->assertStringNotContainsString('secrets', $php);
    }

    public function testMigrateServiceIsOneOffWithOwnerEnv(): void
    {
        $migrate = $this->service('migrate');

        $this->assertStringContainsString('./migrate.env:/var/www/html/.env:ro', $migrate);
        $this->assertStringContainsString('profiles: ["migrate"]', $migrate);
    }

    public function testSeedServiceIsOneOffAppRwAndTheOnlyOneSeeingSeedAdminSecrets(): void
    {
        $seed = $this->service('seed');

        $this->assertStringContainsString('profiles: ["seed"]', $seed);
        $this->assertStringContainsString('./app.env:/var/www/html/.env:ro', $seed);
        $this->assertStringContainsString('./secrets/seed_admin.env:/run/secrets/seed_admin.env:ro', $seed);
        $this->assertStringNotContainsString('migrate.env', $seed);
        $this->assertStringContainsString('"staging:seed"', $seed);

        foreach (['php', 'migrate', 'nginx', 'db'] as $name) {
            $this->assertStringNotContainsString('seed_admin', (string) preg_replace('/^\s*#.*$/m', '', $this->service($name)), "{$name} nesmie vidiet seed_admin.env");
        }

        $this->assertSame(1, substr_count($this->compose(), './secrets/seed_admin.env'));
    }

    public function testDbGetsPasswordsOnlyAsSecretFiles(): void
    {
        $compose = $this->compose();
        $db      = $this->service('db');

        $this->assertDoesNotMatchRegularExpression('/^\s*POSTGRES_PASSWORD\s*:/m', $compose);
        $this->assertStringNotContainsString('${DB_', $compose);
        $this->assertStringContainsString('POSTGRES_PASSWORD_FILE: /run/secrets/db_superuser_password', $db);
        $this->assertStringContainsString('DB_OWNER_PASSWORD_FILE: /run/secrets/db_owner_password', $db);
        $this->assertStringContainsString('DB_RW_PASSWORD_FILE: /run/secrets/db_rw_password', $db);
        $this->assertStringContainsString('file: ./secrets/db_superuser_password', $compose);
    }

    public function testNginxHasNoPublishedPortsAndJoinsEdge(): void
    {
        $compose = $this->compose();
        $nginx   = $this->service('nginx');

        $this->assertDoesNotMatchRegularExpression('/^\s*ports\s*:/m', $nginx);
        $this->assertStringContainsString('NGINX_PROXY_PROTOCOL: proxy_protocol', $nginx);
        $this->assertStringContainsString('${EDGE_ALIAS:?', $nginx);
        $this->assertStringContainsString('external: true', $compose);
        $this->assertStringContainsString('name: ${COMPOSE_PROJECT_NAME:?', $compose);
    }

    public function testProvisionInitReadsPasswordFiles(): void
    {
        $this->makeDir();
        file_put_contents($this->dir . '/owner', "fake-owner\n");
        file_put_contents($this->dir . '/rw', 'fake-rw');

        [$code, $log] = $this->runInit([
            'DB_OWNER_PASSWORD_FILE' => $this->dir . '/owner',
            'DB_RW_PASSWORD_FILE'    => $this->dir . '/rw',
        ]);

        $this->assertSame(0, $code);
        $this->assertSame(2, substr_count($log, 'owner=fake-owner rw=fake-rw'));
    }

    public function testProvisionInitStillAcceptsPlainEnvForDev(): void
    {
        $this->makeDir();

        [$code, $log] = $this->runInit(['DB_OWNER_PASSWORD' => 'dev-owner', 'DB_RW_PASSWORD' => 'dev-rw']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('owner=dev-owner rw=dev-rw', $log);
    }

    public function testProvisionInitFailsWithoutPasswords(): void
    {
        $this->makeDir();

        [$code, $log] = $this->runInit([]);

        $this->assertNotSame(0, $code);
        $this->assertSame('', $log);
    }

    private function compose(): string
    {
        return (string) file_get_contents(ROOTPATH . 'compose.server.yaml');
    }

    /**
     * Blok sluzby (odsadenie 2 medzery) az po dalsiu sluzbu alebo koniec sekcie services.
     */
    private function service(string $name): string
    {
        $this->assertSame(1, preg_match('/^  ' . preg_quote($name, '/') . ':\n(.*?)(?=^  [a-z_]+:\n|^\S)/ms', $this->compose(), $m), "chyba sluzba {$name}");

        return $m[1];
    }

    private function makeDir(): void
    {
        $this->dir = sys_get_temp_dir() . '/provision-init-' . bin2hex(random_bytes(6));

        if (! mkdir($this->dir, 0o700)) {
            throw new RuntimeException('mkdir failed');
        }

        file_put_contents($this->dir . '/psql', "#!/bin/sh\nprintf 'owner=%s rw=%s\\n' \"\$DB_OWNER_PASSWORD\" \"\$DB_RW_PASSWORD\" >> \"\$FAKE_LOG\"\n");
        chmod($this->dir . '/psql', 0o700);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string}
     */
    private function runInit(array $env): array
    {
        $env = array_merge([
            'PATH'          => $this->dir . ':/usr/bin:/bin',
            'POSTGRES_USER' => 'postgres',
            'FAKE_LOG'      => $this->dir . '/calls.log',
        ], $env);

        $proc = proc_open(['sh', ROOTPATH . 'docker/postgres/init/10-provision.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);
        $this->assertIsResource($proc);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $log  = $this->dir . '/calls.log';

        return [$code, is_file($log) ? (string) file_get_contents($log) : ''];
    }
}
