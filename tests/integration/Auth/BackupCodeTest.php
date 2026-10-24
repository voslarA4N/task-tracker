<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Libraries\Auth\BackupCodeService;
use Config\Database;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\AuthTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class BackupCodeTest extends AuthTestCase
{
    private const PATTERN = '/^[23456789ABCDEFGHJKMNPQRSTVWXYZ]{5}-[23456789ABCDEFGHJKMNPQRSTVWXYZ]{5}$/';

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new DateTimeImmutable('2026-10-24T10:00:00+02:00');
    }

    private function svc(): BackupCodeService
    {
        return new BackupCodeService($this->rw());
    }

    public function testGenerateReturnsTenDistinctFormattedCodes(): void
    {
        $user  = $this->createUser();
        $codes = $this->svc()->generate((int) $user->id, $this->now);

        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression(self::PATTERN, $code);
        }

        $this->assertSame(10, $this->svc()->remaining((int) $user->id));
    }

    public function testPlaintextIsNeverStored(): void
    {
        $user  = $this->createUser();
        $codes = $this->svc()->generate((int) $user->id, $this->now);
        $rows  = $this->owner()->query('SELECT * FROM backup_codes WHERE user_id = ?', [$user->id])->getResultArray();

        $this->assertCount(10, $rows);

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['code_hash']);

            foreach ($row as $value) {
                foreach ($codes as $code) {
                    $this->assertStringNotContainsString($code, (string) $value);
                    $this->assertStringNotContainsString(BackupCodeService::normalize($code), (string) $value);
                }
            }
        }
    }

    public function testConsumeIsSingleUse(): void
    {
        $user  = $this->createUser();
        $codes = $this->svc()->generate((int) $user->id, $this->now);

        $this->assertTrue($this->svc()->consume((int) $user->id, $codes[0], $this->now));
        $this->assertFalse($this->svc()->consume((int) $user->id, $codes[0], $this->now));
        $this->assertSame(9, $this->svc()->remaining((int) $user->id));
    }

    public function testConsumeNormalizesInput(): void
    {
        $user  = $this->createUser();
        $codes = $this->svc()->generate((int) $user->id, $this->now);

        $this->assertTrue($this->svc()->consume((int) $user->id, strtolower($codes[0]), $this->now));
        $this->assertTrue($this->svc()->consume((int) $user->id, ' ' . str_replace('-', '', $codes[1]) . ' ', $this->now));
        $this->assertTrue($this->svc()->consume((int) $user->id, str_replace('-', ' ', $codes[2]), $this->now));
    }

    public function testInvalidCodeIsRejected(): void
    {
        $user = $this->createUser();
        $this->svc()->generate((int) $user->id, $this->now);

        $this->assertFalse($this->svc()->consume((int) $user->id, 'AAAAA-AAAAA', $this->now));
        $this->assertFalse($this->svc()->consume((int) $user->id, '', $this->now));
    }

    public function testSecondConnectionCannotReuseCode(): void
    {
        $user  = $this->createUser();
        $codes = $this->svc()->generate((int) $user->id, $this->now);

        $second = new BackupCodeService(Database::connect('tests', false));

        $this->assertTrue($this->svc()->consume((int) $user->id, $codes[0], $this->now));
        $this->assertFalse($second->consume((int) $user->id, $codes[0], $this->now));
    }

    public function testRegenerateInvalidatesOldCodes(): void
    {
        $user = $this->createUser();
        $old  = $this->svc()->generate((int) $user->id, $this->now);
        $this->svc()->consume((int) $user->id, $old[0], $this->now);
        $new = $this->svc()->generate((int) $user->id, $this->now);

        $this->assertSame(10, $this->svc()->remaining((int) $user->id));
        $this->assertFalse($this->svc()->consume((int) $user->id, $old[1], $this->now));
        $this->assertTrue($this->svc()->consume((int) $user->id, $new[0], $this->now));
    }

    public function testDeleteAll(): void
    {
        $user = $this->createUser();
        $this->svc()->generate((int) $user->id, $this->now);
        $this->svc()->deleteAll((int) $user->id);

        $this->assertSame(0, $this->svc()->remaining((int) $user->id));
    }

    public function testEmptyPepperFailsClosed(): void
    {
        $cfg      = config('Auth');
        $original = $cfg->backupCodePepper;
        $cfg->backupCodePepper = '';

        try {
            $user = $this->createUser();
            $this->expectException(RuntimeException::class);
            $this->svc()->generate((int) $user->id, $this->now);
        } finally {
            $cfg->backupCodePepper = $original;
        }
    }
}
