<?php

declare(strict_types=1);

namespace Tests\Feature\Work;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class TaskShowDeleteTest extends AuthTestCase
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
    private function submit(string $url, array $data = []): TestResponse
    {
        $this->withSession();

        return $this->call('post', $url, $data + [csrf_token() => csrf_hash()]);
    }

    public function testDetailEscapesTitleAndShowsLink(): void
    {
        $client = $this->createClient(['portal_name' => 'Taskman']);
        $task   = $this->createTask($client, ['title' => '<script>x</script>', 'url' => 'https://t.example/1']);

        $html = $this->body($this->get('/tasks/' . $task));

        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringContainsString(esc(lang('Tasks.stateInProgress')), $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('href="https://t.example/1"', $html);
        $this->assertStringContainsString(esc(lang('Tasks.sectionData')), $html);
    }

    public function testNoLinkForJavascriptUrl(): void
    {
        $client = $this->createClient();
        $task   = $this->createTask($client, ['url' => 'javascript:alert(1)']);

        $this->assertStringNotContainsString('href="javascript:', $this->body($this->get('/tasks/' . $task)));
    }

    public function testMissingTaskIs404(): void
    {
        $this->expectException(PageNotFoundException::class);
        $this->get('/tasks/999');
    }

    public function testDeleteWithoutLockedParts(): void
    {
        $client = $this->createClient();
        $task   = $this->createTask($client);
        $this->createAllocation($task, '8.00', '2026-10');

        $html = $this->body($this->get('/tasks/' . $task));
        $this->assertStringContainsString(esc(lang('Tasks.delete')), $html);
        $this->assertStringContainsString('action="/tasks/' . $task . '/delete"', $html);

        $result = $this->submit('/tasks/' . $task . '/delete');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/tasks', $result->getRedirectUrl());
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM tasks')->getRow()->c);
    }

    public function testLockedTaskHasNoDeleteAndPostIs409(): void
    {
        $client = $this->createClient();
        $task   = $this->createTask($client);
        $alloc  = $this->createAllocation($task, '8.00', '2026-10');
        $this->lockAllocation($alloc);

        $html = $this->body($this->get('/tasks/' . $task));
        $this->assertStringNotContainsString('action="/tasks/' . $task . '/delete"', $html);
        $this->assertStringContainsString(esc(lang('Tasks.deleteBlockedHint')), $html);

        $result = $this->submit('/tasks/' . $task . '/delete');
        $result->assertStatus(409);
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM tasks')->getRow()->c);
    }

    public function testNavigationTasksIsActive(): void
    {
        $client = $this->createClient();
        $task   = $this->createTask($client);

        $html = $this->body($this->get('/tasks/' . $task));
        $this->assertStringContainsString('<a href="/tasks" aria-current="page">', $html);

        $home = $this->body($this->get('/'));
        $this->assertStringNotContainsString('aria-disabled="true">' . esc(lang('App.navTasks')), $home);
        $this->assertStringContainsString('<a href="/tasks">', $home);
    }
}
