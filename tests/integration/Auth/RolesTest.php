<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Libraries\Auth\UserProvisioner;
use Config\AuthGroups;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * AUTH-08, AUTH-01: skupiny admin/accountant, Argon2id, user_security (spojenie app_rw).
 *
 * @internal
 */
#[Group('integration')]
final class RolesTest extends PgTestCase
{
    private const PASSWORD = 'Zelena-Luka-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanTables();
    }

    protected function tearDown(): void
    {
        $this->cleanTables();
        parent::tearDown();
    }

    public function testConfiguredGroupsAreAdminAndAccountant(): void
    {
        $groups = array_keys(config(AuthGroups::class)->groups);
        sort($groups);

        $this->assertSame(['accountant', 'admin'], $groups);
    }

    public function testAdminGroup(): void
    {
        $user = $this->provisioner()->create('a@example.test', self::PASSWORD, 'admin', 'sk', service('clock')->now());

        $this->assertTrue($user->inGroup('admin'));
    }

    public function testAccountantGroup(): void
    {
        $user = $this->provisioner()->create('b@example.test', self::PASSWORD, 'accountant', 'sk', service('clock')->now());

        $this->assertTrue($user->inGroup('accountant'));
        $this->assertFalse($user->inGroup('admin'));
    }

    public function testInvalidGroupIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->provisioner()->create('c@example.test', self::PASSWORD, 'root', 'sk', service('clock')->now());
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->provisioner()->create('d@example.test', self::PASSWORD, 'admin', 'sk', service('clock')->now());

        $this->expectException(DomainException::class);
        $this->provisioner()->create('d@example.test', self::PASSWORD, 'admin', 'sk', service('clock')->now());
    }

    public function testShortPasswordIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->provisioner()->create('e@example.test', 'kratke', 'admin', 'sk', service('clock')->now());
    }

    public function testUserSecurityRowAndArgon2idHash(): void
    {
        $user = $this->provisioner()->create('f@example.test', self::PASSWORD, 'admin', 'sk', service('clock')->now());

        $row = $this->rw()->query('SELECT locale, auth_epoch FROM user_security WHERE user_id = ?', [$user->id])->getRow();
        $this->assertNotNull($row);
        $this->assertSame('sk', $row->locale);
        $this->assertSame(0, (int) $row->auth_epoch);

        $hash = $this->rw()->query("SELECT secret2 FROM auth_identities WHERE user_id = ? AND type = 'email_password'", [$user->id])->getRow()->secret2;
        $this->assertStringStartsWith('$argon2id$v=19$m=19456,t=2,p=1$', $hash);
    }

    private function provisioner(): UserProvisioner
    {
        return new UserProvisioner();
    }

    private function cleanTables(): void
    {
        $this->owner()->query('TRUNCATE users, auth_identities, auth_groups_users, user_security, auth_logins RESTART IDENTITY CASCADE');
    }
}
