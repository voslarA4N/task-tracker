<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Libraries\Auth\TotpService;
use Config\Database;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class TotpReplayTest extends AuthTestCase
{
    public function testClaimStepIsMonotonic(): void
    {
        $user = $this->createUser();
        $this->enrollTotp($user);
        $svc = new TotpService(null, $this->rw());

        $this->assertTrue($svc->claimStep((int) $user->id, 1000));
        $this->assertFalse($svc->claimStep((int) $user->id, 1000));
        $this->assertFalse($svc->claimStep((int) $user->id, 999));
        $this->assertTrue($svc->claimStep((int) $user->id, 1001));
    }

    public function testSecondConnectionCannotClaimSameStep(): void
    {
        $user = $this->createUser();
        $this->enrollTotp($user);

        $first  = new TotpService(null, $this->rw());
        $second = new TotpService(null, Database::connect('tests', false));

        $this->assertTrue($first->claimStep((int) $user->id, 5000));
        $this->assertFalse($second->claimStep((int) $user->id, 5000));
    }

    public function testUserWithoutTotpCannotClaim(): void
    {
        $user = $this->createUser();

        $this->assertFalse((new TotpService(null, $this->rw()))->claimStep((int) $user->id, 1000));
    }
}
