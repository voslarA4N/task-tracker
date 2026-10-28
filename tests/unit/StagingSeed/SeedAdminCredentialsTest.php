<?php

declare(strict_types=1);

namespace Tests\Unit\StagingSeed;

use App\Libraries\StagingSeed\SeedAdminCredentials;
use App\Libraries\StagingSeed\SeedRefused;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * D-02: prihlasovacie udaje seed admina sa citaju zo suboru a nikdy sa nedostanu do chybovych sprav.
 *
 * @internal
 */
#[Group('unit')]
final class SeedAdminCredentialsTest extends CIUnitTestCase
{
    private const PASSWORD = 'Sup3r-Tajne-Heslo-12345';

    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir() . '/seed_admin_' . bin2hex(random_bytes(6)) . '.env';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    public function testParsesFileWithCommentsAndQuotes(): void
    {
        file_put_contents($this->file, "# seed admin\n\nSEED_ADMIN_EMAIL = \"Owner@Example.Test\"\nSEED_ADMIN_PASSWORD='" . self::PASSWORD . "'\nSEED_ADMIN_TOTP_SECRET=jbsw y3dp ehpk 3pxp jbsw y3dp ehpk 3pxp====\n");

        $c = SeedAdminCredentials::load($this->file, 'production');

        $this->assertSame('owner@example.test', $c->email);
        $this->assertSame(self::PASSWORD, $c->password);
    }

    public function testFileWithoutTotpSecretLoads(): void
    {
        file_put_contents($this->file, "SEED_ADMIN_EMAIL=a@example.test\nSEED_ADMIN_PASSWORD=" . self::PASSWORD . "\n");

        $c = SeedAdminCredentials::load($this->file, 'production');

        $this->assertSame('a@example.test', $c->email);
    }

    public function testInvalidTotpSecretIsIgnored(): void
    {
        file_put_contents($this->file, "SEED_ADMIN_EMAIL=a@example.test\nSEED_ADMIN_PASSWORD=" . self::PASSWORD . "\nSEED_ADMIN_TOTP_SECRET=abc1!\n");

        $this->assertSame(self::PASSWORD, SeedAdminCredentials::load($this->file, 'production')->password);
    }

    public function testMissingFileRefusesInProduction(): void
    {
        $this->assertRefused(fn (): SeedAdminCredentials => SeedAdminCredentials::load($this->file, 'production', $this->fallback()));
    }

    public function testMissingFileUsesFallbackInDevelopment(): void
    {
        $c = SeedAdminCredentials::load($this->file, 'development', $this->fallback());

        $this->assertSame('dev@example.test', $c->email);
    }

    public function testMissingFileAndFallbackRefuses(): void
    {
        $this->assertRefused(fn (): SeedAdminCredentials => SeedAdminCredentials::load($this->file, 'development', []));
    }

    public function testInvalidValuesRefuseWithoutLeakingThem(): void
    {
        $cases = [
            'bad email'      => ['SEED_ADMIN_EMAIL' => 'not-an-email'],
            'empty password' => ['SEED_ADMIN_PASSWORD' => ''],
        ];

        foreach ($cases as $name => $override) {
            $fallback = $override + $this->fallback();

            try {
                SeedAdminCredentials::load($this->file, 'development', $fallback);
                $this->fail($name . ': expected SeedRefused');
            } catch (SeedRefused $e) {
                $this->assertSame(SeedRefused::CREDENTIALS, $e->reason, $name);

                foreach ($fallback as $value) {
                    if ($value !== '') {
                        $this->assertStringNotContainsString($value, $e->getMessage(), $name);
                    }
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function fallback(): array
    {
        return [
            'SEED_ADMIN_EMAIL'    => 'dev@example.test',
            'SEED_ADMIN_PASSWORD' => self::PASSWORD,
        ];
    }

    private function assertRefused(callable $fn): void
    {
        try {
            $fn();
        } catch (SeedRefused $e) {
            $this->assertSame(SeedRefused::CREDENTIALS, $e->reason);

            return;
        }

        $this->fail('Expected SeedRefused');
    }
}
