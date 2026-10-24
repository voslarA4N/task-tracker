<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class EncryptionConfigTest extends CIUnitTestCase
{
    public function testDriverIsSodiumWithKey(): void
    {
        $config = config('Encryption');
        $this->assertSame('Sodium', $config->driver);
        $this->assertNotSame('', $config->key);
    }
}
