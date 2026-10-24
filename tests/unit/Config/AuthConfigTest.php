<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Authentication\Actions\TotpAction;
use CodeIgniter\Shield\Authentication\Passwords\PwnedValidator;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Auth;
use Config\Session;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class AuthConfigTest extends CIUnitTestCase
{
    public function testActionsAndFlags(): void
    {
        $cfg = config(Auth::class);

        $this->assertSame(TotpAction::class, $cfg->actions['login']);
        $this->assertNull($cfg->actions['register']);
        $this->assertFalse($cfg->allowRegistration);
        $this->assertFalse($cfg->allowMagicLinkLogins);
        $this->assertFalse($cfg->recordActiveDate);
        $this->assertFalse($cfg->sessionConfig['allowRemembering']);
    }

    public function testPasswordPolicy(): void
    {
        $cfg = config(Auth::class);

        $this->assertSame(12, $cfg->minimumPasswordLength);
        $this->assertSame(PASSWORD_ARGON2ID, $cfg->hashAlgorithm);
        $this->assertSame(19456, $cfg->hashMemoryCost);
        $this->assertSame(2, $cfg->hashTimeCost);
        $this->assertSame(1, $cfg->hashThreads);
        $this->assertNotContains(PwnedValidator::class, $cfg->passwordValidators);
    }

    public function testSessionCookieName(): void
    {
        $this->assertSame('__Host-ci_session', config(Session::class)->cookieName);
    }
}
