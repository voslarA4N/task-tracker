<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Authentication\Actions\TotpAction;
use App\Libraries\Auth\UserProvisioner;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * Spike (02-03): sest kriterii z RESEARCH. Heslo vedie len do pending stavu,
 * prihlasenie dokonci vyhradne checkAction() s nonce akcie totp_gateway.
 *
 * @internal
 */
#[Group('integration')]
final class ShieldPendingStateTest extends PgTestCase
{
    private const EMAIL    = 'spike@example.test';
    private const PASSWORD = 'Spike-Heslo-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanTables();
        $this->resetSession();
    }

    protected function tearDown(): void
    {
        $this->cleanTables();
        parent::tearDown();
    }

    public function testCorrectPasswordLeadsOnlyToPendingState(): void
    {
        $user = $this->createUser();

        $result = auth('session')->attempt(['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertTrue($result->isOK());
        $this->assertFalse(auth('session')->loggedIn());
        $this->assertTrue(auth('session')->getAuthenticator()->isPending());
        $this->assertSame($user->id, auth('session')->getAuthenticator()->getPendingUser()?->id);
    }

    public function testOnlyCheckActionCompletesLogin(): void
    {
        $user = $this->createUser();
        auth('session')->attempt(['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $identities = model(UserIdentityModel::class);
        $identity   = $identities->getIdentityByType($user, TotpAction::TYPE);
        $this->assertNotNull($identity);

        $auth = auth('session')->getAuthenticator();
        $this->assertFalse($auth->checkAction($identity, 'zle'));
        $this->assertFalse(auth('session')->loggedIn());
        $this->assertTrue($auth->isPending());

        $this->assertTrue($auth->checkAction($identity, $identity->secret));
        $this->assertTrue(auth('session')->loggedIn());
        $this->assertNull($identities->getIdentityByType($user, TotpAction::TYPE));
    }

    public function testAbandonedPendingThenNewAttemptWorks(): void
    {
        $this->createUser();

        $first = auth('session')->attempt(['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertTrue($first->isOK());

        auth('session')->logout();

        $second = auth('session')->attempt(['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertTrue($second->isOK());
        $this->assertTrue(auth('session')->getAuthenticator()->isPending());
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testDirectLoginWorksAfterStaleIdentityCleanup(): void
    {
        $user = $this->createUser();
        model(UserIdentityModel::class)->createCodeIdentity(
            $user,
            ['type' => TotpAction::TYPE, 'name' => 'login', 'extra' => ''],
            static fn (): string => 'zabudnuty-nonce',
        );

        model(UserIdentityModel::class)->deleteIdentitiesByType($user, TotpAction::TYPE);
        auth('session')->getAuthenticator()->login($user);

        $this->assertTrue(auth('session')->loggedIn());
    }

    public function testWrongPasswordFailsWithoutUserInSession(): void
    {
        $this->createUser();

        $result = auth('session')->attempt(['email' => self::EMAIL, 'password' => 'uplne-zle-heslo-123']);

        $this->assertFalse($result->isOK());
        $this->assertFalse(auth('session')->loggedIn());
        $this->assertFalse(auth('session')->getAuthenticator()->isPending());
        $this->assertNull(auth('session')->user());
    }

    private function createUser(): User
    {
        return (new UserProvisioner())->create(self::EMAIL, self::PASSWORD, 'admin', 'sk', service('clock')->now());
    }

    private function resetSession(): void
    {
        $_SESSION = [];
        service('auth')->logout();
    }

    private function cleanTables(): void
    {
        $db = $this->owner();
        $db->query('TRUNCATE users, auth_identities, auth_groups_users, user_security, auth_logins RESTART IDENTITY CASCADE');
    }
}
