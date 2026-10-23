<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\Seeds\DevSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * @internal
 */
#[Group('unit')]
final class DevSeederGuardTest extends CIUnitTestCase
{
    public function testRefusesProduction(): void
    {
        $this->expectException(RuntimeException::class);
        DevSeeder::assertAllowed('production');
    }

    public function testAllowsDevelopmentAndTesting(): void
    {
        DevSeeder::assertAllowed('development');
        DevSeeder::assertAllowed('testing');
        $this->addToAssertionCount(1);
    }
}
