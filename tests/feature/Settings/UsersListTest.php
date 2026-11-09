<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Zoznam používateľov len na čítanie (UI-03, D-16).
 *
 * @internal
 */
#[Group('feature')]
final class UsersListTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testListShowsRolesTwofaAndLastLogin(): void
    {
        $admin = $this->createUser('owner@example.test', 'admin');
        $this->enrollTotp($admin);
        $this->createUser('ucto@example.test', 'accountant');
        $this->owner()->query(
            "INSERT INTO auth_events (occurred_at, user_id, event, success, ip) VALUES ('2026-11-03T08:30:00+01:00', ?, 'login_totp_ok', true, '127.0.0.1')",
            [$admin->id],
        );
        $this->loginAs($admin);

        $result = $this->get('/settings/users');
        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();

        $this->assertStringContainsString('owner@example.test', $body);
        $this->assertStringContainsString('ucto@example.test', $body);
        $this->assertStringContainsString('Administrátor', $body);
        $this->assertStringContainsString('Účtovníčka', $body);
        $this->assertStringContainsString('Zapnuté', $body);
        $this->assertStringContainsString('Vypnuté', $body);
        $this->assertStringContainsString('3. 11. 2026 08:30', $body);
        $this->assertStringContainsString('nikdy', $body);
        $this->assertStringNotContainsString('<form method="post" action="/settings/users', $body); // header ma form odhlasenia
        $this->assertStringNotContainsString('/settings/users/new', $body);
    }

    public function testAccountantIsForbidden(): void
    {
        $user = $this->createUser('ucto@example.test', 'accountant');
        $this->enrollTotp($user);
        $this->loginAs($user);
        $this->get('/settings/users')->assertStatus(403);
    }

    public function testMoreMenuLinksToUsers(): void
    {
        $admin = $this->createUser('owner@example.test', 'admin');
        $this->enrollTotp($admin);
        $this->loginAs($admin);
        $this->assertStringContainsString('href="/settings/users"', (string) $this->get('/more')->response()->getBody());
    }

    public function testNoWriteRoute(): void
    {
        $admin = $this->createUser('owner@example.test', 'admin');
        $this->enrollTotp($admin);
        $this->loginAs($admin);
        $this->expectException(PageNotFoundException::class);
        $this->post('/settings/users', []);
    }
}
