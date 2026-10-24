<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Auth\UserProvisioner;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use RuntimeException;

/**
 * Zaklad feature testov za prihlasovacou branou. Dalsie plany sem pridavaju pomocnikov.
 */
abstract class AuthTestCase extends PgTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    public const PASSWORD = 'Zelena-Luka-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTPS', 'on');
        $_SESSION = [];
        auth('session')->logout();
        $this->cleanAuthTables();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        auth('session')->logout();
        $this->cleanAuthTables();
        parent::tearDown();
    }

    protected function createUser(string $email = 'owner@example.test', string $group = 'admin', string $password = self::PASSWORD): User
    {
        return (new UserProvisioner())->create($email, $password, $group, 'sk', service('clock')->now());
    }

    /**
     * Plne prihlasenie (po hesle aj 2FA).
     */
    protected function loginAs(User $user): static
    {
        $this->actingAs($user);

        return $this;
    }

    /**
     * Stav po spravnom hesle, pred 2FA.
     */
    protected function startPending(User $user): static
    {
        $result = auth('session')->attempt(['email' => $user->email, 'password' => self::PASSWORD]);

        if (! $result->isOK()) {
            throw new RuntimeException('startPending: ' . $result->reason());
        }

        $authenticator = auth('session')->getAuthenticator();
        assert($authenticator instanceof Session);

        return $this;
    }

    protected function cleanAuthTables(): void
    {
        $this->owner()->query(
            'TRUNCATE users, auth_identities, auth_logins, auth_groups_users, auth_permissions_users, auth_remember_tokens, user_security RESTART IDENTITY CASCADE',
        );
    }
}
