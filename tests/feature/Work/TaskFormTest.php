<?php

declare(strict_types=1);

namespace Tests\Feature\Work;

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
final class TaskFormTest extends AuthTestCase
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
     * @param array<string, string> $over
     *
     * @return array<string, string>
     */
    private function taskPost(int $clientId, array $over = []): array
    {
        return $over + [
            'client_id' => (string) $clientId,
            'url'       => '',
            'title'     => 'Nova uloha',
            'hours'     => '8,5',
            'rate'      => '27',
            'note'      => '',
        ];
    }

    /**
     * @param array<string, string> $data
     */
    private function submit(string $url, array $data): TestResponse
    {
        $this->withSession();

        return $this->call('post', $url, $data + [csrf_token() => csrf_hash()]);
    }

    public function testNoClientsShowsEmptyState(): void
    {
        $result = $this->get('/tasks/new');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString(esc(lang('Tasks.noClientHeading')), $html);
        $this->assertStringContainsString('href="/settings/clients/new"', $html);
        $this->assertStringNotContainsString('x-data="taskForm"', $html);
    }

    public function testLastUsedClientIsPreselectedAndArchivedHidden(): void
    {
        $first    = $this->createClient(['name' => 'Alfa']);
        $second   = $this->createClient(['name' => 'Beta', 'hourly_rate' => '30.00']);
        $archived = $this->createClient(['name' => 'Gama']);
        $this->owner()->query('UPDATE clients SET archived_at = now() WHERE id = :id:', ['id' => $archived]);
        $this->createTask($second);

        $html = $this->body($this->get('/tasks/new'));

        $this->assertMatchesRegularExpression('/<option value="' . $second . '" selected>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="' . $first . '" selected>/', $html);
        $this->assertStringNotContainsString('Gama', $html);
        $this->assertStringContainsString('x-data="taskForm"', $html);
        $this->assertStringContainsString('data-rate="30.00"', $html);
        $this->assertStringContainsString('hx-get="/tasks/client-defaults"', $html);
        $this->assertSame(2, substr_count($html, 'inputmode="decimal"'));
        $this->assertStringContainsString('type="url"', $html);
    }

    public function testCreateRedirectsToDetailWithFlash(): void
    {
        $client = $this->createClient();
        $result = $this->submit('/tasks', $this->taskPost($client));
        $result->assertStatus(302);

        $row = $this->owner()->query('SELECT id, status, hours, rate FROM tasks')->getRow();
        $this->assertStringEndsWith('/tasks/' . $row->id, $result->getRedirectUrl());
        $this->assertSame('in_progress', $row->status);
        $this->assertSame('8.50', (string) $row->hours);

        $this->withSession();
        $this->assertStringContainsString(esc(lang('Tasks.saved')), $this->body($this->get('/tasks/' . $row->id)));
    }

    public function testForgedAmountAndStatusAreIgnored(): void
    {
        $client = $this->createClient();
        $this->submit('/tasks', $this->taskPost($client, ['amount' => '1', 'status' => 'done']))->assertStatus(302);

        $this->assertSame('in_progress', (string) $this->owner()->query('SELECT status FROM tasks')->getRow()->status);
    }

    public function testZeroHoursIs422(): void
    {
        $client = $this->createClient();
        $result = $this->submit('/tasks', $this->taskPost($client, ['hours' => '0']));
        $result->assertStatus(422);
        $html = $this->body($result);

        $this->assertStringContainsString(esc(lang('Tasks.errorHours')), $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM tasks')->getRow()->c);
    }

    public function testArchivedClientIs422(): void
    {
        $client = $this->createClient();
        $this->owner()->query('UPDATE clients SET archived_at = now() WHERE id = :id:', ['id' => $client]);

        $result = $this->submit('/tasks', $this->taskPost($client));
        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Tasks.errorClient')), $this->body($result));
    }

    public function testClientDefaultsFragment(): void
    {
        $client = $this->createClient(['hourly_rate' => '31.50']);
        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/tasks/client-defaults?client_id=' . $client);
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString('31.50', $html);
        $this->assertStringContainsString('EUR', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringContainsString('HX-Request', $result->response()->getHeaderLine('Vary'));
    }

    public function testPortalPreviewIsText(): void
    {
        $client = $this->createClient(['portal_name' => 'Taskman', 'portal_url_template' => 'https://t.example/task/{id}']);
        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/tasks/portal-preview?client_id=' . $client . '&url=81252');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString('https://t.example/task/81252', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testEditWithAllocationLocksClient(): void
    {
        $a    = $this->createClient(['name' => 'Alfa']);
        $b    = $this->createClient(['name' => 'Beta']);
        $task = $this->createTask($a);
        $this->createAllocation($task, '8.00', '2026-10');

        $html = $this->body($this->get('/tasks/' . $task . '/edit'));
        $this->assertStringContainsString('master-fixed', $html);
        $this->assertStringContainsString(esc(lang('Tasks.clientLockedHint')), $html);

        $result = $this->submit('/tasks/' . $task, $this->taskPost($b));
        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Tasks.errorClientLocked')), $this->body($result));
    }
}
