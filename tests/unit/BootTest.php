<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\CodeIgniter;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class BootTest extends CIUnitTestCase
{
    public function testEnvironmentIsTesting(): void
    {
        $this->assertSame('testing', ENVIRONMENT);
    }

    public function testPhpVersion(): void
    {
        $this->assertGreaterThanOrEqual(80500, PHP_VERSION_ID);
    }

    public function testBcmathAvailable(): void
    {
        $this->assertTrue(extension_loaded('bcmath'));
        $this->assertTrue(function_exists('bcround'));
    }

    public function testFrameworkVersion(): void
    {
        $this->assertStringStartsWith('4.7.', CodeIgniter::CI_VERSION);
    }
}
