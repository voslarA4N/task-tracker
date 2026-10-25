<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class EntitiesListTest extends AuthTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    public function testEmptyStateLinksToNewEntity(): void
    {
        $result = $this->get('/settings/entities');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString(esc(lang('Entities.emptyHeading')), $html);
        $this->assertStringContainsString('href="/settings/entities/new"', $html);
    }

    public function testListShowsActiveAndEndedEntitiesAsLinks(): void
    {
        $a = $this->createEntity('SK', ['name_internal' => 'Aktivna firma', 'active_from' => '2026-01-01']);
        $b = $this->createEntity('SK', ['name_internal' => 'Stara firma', 'active_from' => '2025-01-01', 'active_to' => '2025-12-31']);

        $this->withSession();
        $result = $this->get('/settings/entities');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString('Aktivna firma', $html);
        $this->assertStringContainsString('Stara firma', $html);
        $this->assertStringContainsString(esc(lang('Entities.statusActive')), $html);
        $this->assertStringContainsString(esc(lang('Entities.statusEnded')), $html);
        $this->assertStringContainsString('href="/settings/entities/' . $a . '"', $html);
        $this->assertStringContainsString('href="/settings/entities/' . $b . '"', $html);
    }

    public function testMoreLinksToEntitiesBeforeSecurity(): void
    {
        $result = $this->get('/more');
        $result->assertStatus(200);
        $html = $this->body($result);

        $entities = strpos($html, 'href="/settings/entities"');
        $security = strpos($html, 'href="/settings/security"');

        $this->assertNotFalse($entities);
        $this->assertNotFalse($security);
        $this->assertLessThan($security, $entities);
    }
}
