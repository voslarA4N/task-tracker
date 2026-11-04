<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;

/**
 * Prehliadač denníka zmien (AUD-02, D-13).
 *
 * @internal
 */
#[Group('feature')]
final class AuditViewerTest extends AuthTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function login(string $group = 'admin'): void
    {
        $user = $this->createUser('owner@example.test', $group);
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function entry(string $type, string $action, ?string $before, ?string $after): int
    {
        $this->owner()->query(
            'INSERT INTO audit_log (occurred_at, user_id, entity_type, entity_id, action, before_data, after_data, ip) VALUES (?, NULL, ?, 7, ?, ?::jsonb, ?::jsonb, ?)',
            ['2026-11-03T10:00:00+01:00', $type, $action, $before, $after, '127.0.0.1'],
        );

        return (int) $this->owner()->insertID();
    }

    public function testListShowsRecords(): void
    {
        $this->entry('invoice', 'issue', null, '{"a":"1"}');
        $this->login();
        $result = $this->get('/settings/audit');
        $result->assertStatus(200);
        $body = $this->body($result);

        $this->assertStringContainsString('Faktúra', $body);
        $this->assertStringContainsString('Vystavené', $body);
        $this->assertStringContainsString('id="audit-list"', $body);
        $this->assertStringContainsString('3. 11. 2026', $body);
    }

    public function testEntityFilter(): void
    {
        $this->entry('invoice', 'issue', null, '{"a":"1"}');
        $this->entry('task', 'create', null, '{"a":"1"}');
        $this->login();
        $body = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/settings/audit?entity=invoice'));

        $this->assertStringStartsWith('<div id="audit-list"', trim($body));
        $this->assertStringContainsString('Vystavené', $body);
        $this->assertStringNotContainsString('Úloha', $body);
    }

    public function testUnknownEntityShowsRawKey(): void
    {
        $this->entry('weird_thing', 'create', null, '{"a":"1"}');
        $this->login();
        $this->assertStringContainsString('weird_thing', $this->body($this->get('/settings/audit')));
    }

    public function testDetailMarksChangedFields(): void
    {
        $id = $this->entry('client', 'update', '{"name":"Stará <b>"}', '{"name":"Nová"}');
        $this->login();
        $body = $this->body($this->get('/settings/audit/' . $id));

        $this->assertStringContainsString('aud-changed', $body);
        $this->assertStringContainsString('zmenené', $body);
        $this->assertStringContainsString('Stará &lt;b&gt;', $body);
        $this->assertStringNotContainsString('Stará <b>', $body);
    }

    public function testDetailCreateShowsDashBefore(): void
    {
        $id = $this->entry('client', 'create', null, '{"name":"Nová"}');
        $this->login();
        $this->assertStringContainsString('—', $this->body($this->get('/settings/audit/' . $id)));
    }

    public function testUnknownIdIs404(): void
    {
        $this->login();
        $this->expectException(PageNotFoundException::class);
        $this->get('/settings/audit/999999');
    }

    public function testAccountantIsForbidden(): void
    {
        $this->login('accountant');
        $this->get('/settings/audit')->assertStatus(403);
    }

    public function testNoWriteRoute(): void
    {
        $this->login();
        $this->expectException(PageNotFoundException::class);
        $this->post('/settings/audit', []);
    }

    public function testMoreLinksToAudit(): void
    {
        $this->login();
        $this->assertStringContainsString('href="/settings/audit"', $this->body($this->get('/more')));
    }
}
