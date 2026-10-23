<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * WR-09: prepnutie defaultGroup na app_owner pri `spark migrate* -g <skupina>`.
 *
 * @internal
 */
#[Group('unit')]
final class DatabaseConfigTest extends CIUnitTestCase
{
    /**
     * @param list<string> $argv
     */
    #[DataProvider('provideMigrationOwnerGroup')]
    public function testMigrationOwnerGroup(string $environment, array $argv, ?string $expected): void
    {
        $this->assertSame($expected, Database::migrationOwnerGroup($environment, $argv));
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>, 2: string|null}>
     */
    public static function provideMigrationOwnerGroup(): iterable
    {
        $spark = 'spark';

        return [
            'dev migrate -g owner'             => ['development', [$spark, 'migrate', '-g', 'owner', '--all'], 'owner'],
            'dev migrate:status -g owner'      => ['development', [$spark, 'migrate:status', '-g', 'owner'], 'owner'],
            'dev migrate -g tests_owner'       => ['development', [$spark, 'migrate', '-g', 'tests_owner', '--all'], 'tests_owner'],
            'dev --no-header before command'   => ['development', [$spark, '--no-header', 'migrate', '-g', 'owner'], 'owner'],
            'dev --no-header after command'    => ['development', [$spark, 'migrate', '--no-header', '-g', 'owner'], 'owner'],
            'dev options before command'       => ['development', [$spark, '--all', '-g', 'owner', 'migrate'], 'owner'],
            'dev --g long form'                => ['development', [$spark, 'migrate', '--g', 'owner'], 'owner'],
            'dev unknown group'                => ['development', [$spark, 'migrate', '-g', 'default'], null],
            'dev -g without value'             => ['development', [$spark, 'migrate', '-g'], null],
            'dev not a migrate command'        => ['development', [$spark, 'db:seed', '-g', 'owner'], null],
            'dev no command'                   => ['development', [$spark], null],
            'production migrate -g owner'      => ['production', [$spark, 'migrate', '-g', 'owner'], 'owner'],
            'testing migrate -g owner (live)'  => ['testing', [$spark, 'migrate', '-g', 'owner', '--all'], null],
            'testing migrate -g tests_owner'   => ['testing', [$spark, 'migrate', '-g', 'tests_owner', '--all'], 'tests_owner'],
            'testing rollback -g owner (live)' => ['testing', [$spark, 'migrate:rollback', '-g', 'owner'], null],
        ];
    }

    public function testTestingDefaultsToTestsGroup(): void
    {
        $this->assertSame('tests', config(Database::class)->defaultGroup);
    }
}
