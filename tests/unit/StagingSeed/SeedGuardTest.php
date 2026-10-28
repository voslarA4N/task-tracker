<?php

declare(strict_types=1);

namespace Tests\Unit\StagingSeed;

use App\Libraries\StagingSeed\SeedGuard;
use App\Libraries\StagingSeed\SeedRefused;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * D-01: kazda poistka proti seedovaniu produkcie stoji sama.
 *
 * @internal
 */
#[Group('unit')]
final class SeedGuardTest extends CIUnitTestCase
{
    private const STAGING = 'https://work-tracker-stage.vostack.dev/';

    /**
     * @return array<string, int>
     */
    private static function empty(): array
    {
        return ['invoices' => 0, 'entities' => 0, 'clients' => 0, 'tasks' => 0, 'users' => 0];
    }

    public function testPassesOnStagingWithEmptyDatabase(): void
    {
        SeedGuard::check(true, self::STAGING, static fn (): array => self::empty());
        $this->addToAssertionCount(1);
    }

    public function testRefusesWhenFlagIsOff(): void
    {
        $this->assertReason(SeedRefused::FLAG_OFF, false, self::STAGING, self::empty());
    }

    #[DataProvider('provideRefusesProductionHost')]
    public function testRefusesProductionHost(string $url): void
    {
        $this->assertReason(SeedRefused::PRODUCTION_HOST, true, $url, self::empty());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusesProductionHost(): iterable
    {
        yield 'plain' => ['https://work-tracker.vostack.dev/'];

        yield 'uppercase' => ['https://WORK-Tracker.Vostack.DEV/'];

        yield 'trailing dot' => ['https://work-tracker.vostack.dev./'];

        yield 'with path and port' => ['https://work-tracker.vostack.dev:443/app/'];

        yield 'no host' => ['/relative/'];
    }

    #[DataProvider('provideRefusesNonPristineDatabase')]
    public function testRefusesNonPristineDatabase(string $table): void
    {
        $this->assertReason(SeedRefused::NOT_PRISTINE, true, self::STAGING, [$table => 1] + self::empty());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusesNonPristineDatabase(): iterable
    {
        foreach (SeedGuard::TABLES as $table) {
            yield $table => [$table];
        }
    }

    /**
     * @param array<string, int> $counts
     */
    private function assertReason(string $reason, bool $flag, string $url, array $counts): void
    {
        try {
            SeedGuard::check($flag, $url, static fn (): array => $counts);
        } catch (SeedRefused $e) {
            $this->assertSame($reason, $e->reason);

            return;
        }

        $this->fail('Expected SeedRefused ' . $reason);
    }
}
