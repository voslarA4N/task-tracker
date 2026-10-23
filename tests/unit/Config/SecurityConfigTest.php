<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Filters\HtmxCsrf;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Cookie;
use Config\Filters;
use Config\Security;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class SecurityConfigTest extends CIUnitTestCase
{
    public function testSecurityConfig(): void
    {
        $cfg = config(Security::class);

        $this->assertSame('session', $cfg->csrfProtection);
        $this->assertTrue($cfg->tokenRandomize);
        $this->assertFalse($cfg->regenerate);
        $this->assertFalse($cfg->redirect);
    }

    public function testCookieConfig(): void
    {
        $cfg = config(Cookie::class);

        $this->assertTrue($cfg->secure);
        $this->assertTrue($cfg->httponly);
        $this->assertSame('Lax', $cfg->samesite);
    }

    public function testFiltersConfig(): void
    {
        $cfg = config(Filters::class);

        $this->assertSame(HtmxCsrf::class, $cfg->aliases['csrf']);
        $this->assertNotContains('toolbar', $cfg->required['after']);
        $this->assertContains('csrf', $cfg->globals['before']);
        $this->assertNotContains('secureheaders', $cfg->globals['after']);
        $this->assertNotContains('secureheaders', $cfg->required['after']);
    }
}
