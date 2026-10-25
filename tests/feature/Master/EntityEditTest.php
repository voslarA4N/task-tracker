<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Libraries\Master\EntityDirectory;
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
final class EntityEditTest extends AuthTestCase
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

    /**
     * @param array<string, string> $data
     */
    private function update(int $id, array $data): TestResponse
    {
        $this->withSession();

        return $this->call('post', '/settings/entities/' . $id, $data + [csrf_token() => csrf_hash()]);
    }

    /**
     * @param array<string, string> $data
     */
    private function endIt(int $id, array $data): TestResponse
    {
        $this->withSession();

        return $this->call('post', '/settings/entities/' . $id . '/end', $data + [csrf_token() => csrf_hash()]);
    }

    private function auditCount(string $action): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM audit_log WHERE entity_type = ? AND action = ?', ['entity', $action])->getRow()->c;
    }

    public function testEditFormShowsFields(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->get('/settings/entities/' . $id . '/edit');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString('name="name_internal"', $html);
        $this->assertStringContainsString('name="active_from"', $html);
        $this->assertStringContainsString('name="active_to"', $html);
        $this->assertStringContainsString('master-fixed', $html);
        $this->assertStringNotContainsString('name="trade_name"', $html);
        $this->assertStringNotContainsString('name="country"', $html);
    }

    public function testRenameAuditsOnlyName(): void
    {
        $id = $this->createEntity('SK');
        $this->update($id, ['name_internal' => 'Nový názov', 'active_from' => '2026-01-01', 'active_to' => ''])->assertStatus(302);

        $this->assertSame('Nový názov', $this->owner()->query('SELECT name_internal FROM entities WHERE id = ?', [$id])->getRow()->name_internal);
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM entity_versions')->getRow()->c);

        $row    = $this->owner()->query("SELECT before_data, after_data FROM audit_log WHERE entity_type = 'entity' AND action = 'update'")->getRow();
        $before = json_decode((string) $row->before_data, true);
        $after  = json_decode((string) $row->after_data, true);
        $this->assertSame(['name_internal' => 'Moja firma SK'], $before);
        $this->assertSame(['name_internal' => 'Nový názov'], $after);

        $this->withSession();
        $this->assertStringContainsString('alert-success', $this->body($this->get('/settings/entities/' . $id)));
    }

    public function testActiveToBeforeFromIs422(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->update($id, ['name_internal' => 'X', 'active_from' => '2026-01-01', 'active_to' => '2025-12-31']);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Entities.errorActiveToBeforeFrom')), $this->body($result));
    }

    public function testActiveFromBeforeFirstVersionIs422(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->update($id, ['name_internal' => 'X', 'active_from' => '2025-12-01', 'active_to' => '']);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Entities.errorActiveFromBeforeFirstVersion', ['1. 1. 2026'])), $this->body($result));
        $this->assertSame('2026-01-01', $this->owner()->query('SELECT active_from FROM entities WHERE id = ?', [$id])->getRow()->active_from);
    }

    public function testDetailHasEndButtonAndModal(): void
    {
        $id   = $this->createEntity('SK');
        $html = $this->body($this->get('/settings/entities/' . $id));

        $this->assertStringContainsString(esc(lang('Entities.endButton')), $html);
        $this->assertStringContainsString('action="/settings/entities/' . $id . '/end"', $html);
        $this->assertStringContainsString('name="active_to"', $html);
        $this->assertStringContainsString('value="2026-10-25"', $html);
    }

    public function testEndTodayKeepsActiveAndFlashes(): void
    {
        $id = $this->createEntity('SK');
        $this->endIt($id, ['active_to' => '2026-10-25'])->assertStatus(302);

        $this->assertSame(1, $this->auditCount('end'));

        $ids = array_column((new EntityDirectory())->forSelect('2026-10-25'), 'id');
        $this->assertContains($id, $ids);

        $this->withSession();
        $html = $this->body($this->get('/settings/entities'));
        $this->assertStringContainsString(esc(lang('Entities.statusActive')), $html);
        $this->assertStringNotContainsString(esc(lang('Entities.statusEnded')), $html);
    }

    public function testEndedEntityStaysVisibleAndLosesEndButton(): void
    {
        $id = $this->createEntity('SK');
        $this->endIt($id, ['active_to' => '2026-10-24'])->assertStatus(302);

        $ids = array_column((new EntityDirectory())->forSelect('2026-10-25'), 'id');
        $this->assertContains($id, $ids);

        $this->withSession();
        $this->assertStringContainsString(esc(lang('Entities.statusEnded')), $this->body($this->get('/settings/entities')));

        $this->withSession();
        $this->assertStringNotContainsString(esc(lang('Entities.endButton')), $this->body($this->get('/settings/entities/' . $id)));
    }

    public function testEndBeforeActiveFromIs422(): void
    {
        $id = $this->createEntity('SK');
        $this->endIt($id, ['active_to' => '2025-12-31'])->assertStatus(422);
        $this->assertNull($this->owner()->query('SELECT active_to FROM entities WHERE id = ?', [$id])->getRow()->active_to);
    }

    public function testEmptyActiveToCancelsEnd(): void
    {
        $id = $this->createEntity('SK');
        $this->endIt($id, ['active_to' => '2026-10-24'])->assertStatus(302);
        $this->update($id, ['name_internal' => 'Moja firma SK', 'active_from' => '2026-01-01', 'active_to' => ''])->assertStatus(302);

        $this->assertNull($this->owner()->query('SELECT active_to FROM entities WHERE id = ?', [$id])->getRow()->active_to);
    }
}
