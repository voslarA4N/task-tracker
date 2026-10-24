<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Auth\TotpService;
use App\Libraries\Auth\UserProvisioner;
use App\Libraries\Platform\PsrClock;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use OTPHP\TOTP;
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

    /**
     * Aktivuje TOTP pre pouzivatela (sifrovane tajomstvo + totp_enabled_at), vrati otvorene tajomstvo.
     */
    protected function enrollTotp(User $user): string
    {
        $svc    = new TotpService();
        $secret = $svc->newSecret();
        $now    = service('clock')->now()->format('Y-m-d H:i:sP');

        $this->owner()->query(
            'UPDATE user_security SET totp_secret_enc = ?, totp_enabled_at = ?, totp_last_step = NULL WHERE user_id = ?',
            [$svc->encryptSecret($secret), $now, $user->id],
        );

        return $secret;
    }

    /**
     * Platny kod pre cas service('clock') posunuty o $offsetSeconds.
     */
    protected function totpCode(string $secret, int $offsetSeconds = 0): string
    {
        $clock = service('clock');

        return TOTP::createFromSecret($secret, new PsrClock($clock))->at($clock->now()->getTimestamp() + $offsetSeconds);
    }

    protected function cleanAuthTables(): void
    {
        $this->owner()->query(
            'TRUNCATE users, auth_identities, auth_logins, auth_groups_users, auth_permissions_users, auth_remember_tokens, user_security, auth_lockouts, auth_events RESTART IDENTITY CASCADE',
        );
    }
}
