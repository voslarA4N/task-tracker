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
final class TasksListTableTest extends AuthTestCase
{
    use MasterFixtures;

    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
        $this->client = $this->createClient();
        $this->createTask($this->client, ['title' => 'Uloha Medium', 'hours' => '5.00']);
        $this->createTask($this->client, ['title' => 'Uloha Velka', 'hours' => '9.00']);
        $this->createTask($this->client, ['title' => 'Uloha Mala', 'hours' => '1.00']);
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

    public function testSortByHoursAscending(): void
    {
        $html = $this->body($this->get('/tasks?state=all&sort=hours&dir=asc'));

        $table = substr($html, (int) strpos($html, '<table'));
        $this->assertLessThan(strpos($table, 'Uloha Medium'), strpos($table, 'Uloha Mala'));
        $this->assertLessThan(strpos($table, 'Uloha Velka'), strpos($table, 'Uloha Medium'));
        $this->assertStringContainsString('aria-sort="ascending"', $html);
    }

    public function testDefaultRendersTableAndCards(): void
    {
        $html = $this->body($this->get('/tasks'));

        $this->assertStringContainsString('dt-only-lg', $html);
        $this->assertStringContainsString('dt-only-sm', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('aria-sort="ascending"', $html);
    }

    public function testEvilSortFallsBackToDefault(): void
    {
        $html = $this->body($this->get('/tasks?sort=evil&dir=asc'));

        $this->assertStringContainsString('Uloha Mala', $html);
        $this->assertStringNotContainsString('aria-sort="ascending"', $html);
    }

    public function testClientFilterKeptInSortLinks(): void
    {
        $html = $this->body($this->get('/tasks?client=' . $this->client));

        $this->assertStringContainsString('client&#x3D;' . $this->client, $html);
    }

    public function testHtmxReturnsFragment(): void
    {
        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/tasks?sort=hours'));

        $this->assertStringContainsString('id="task-list"', $html);
        $this->assertStringNotContainsString('<html', $html);
    }
}
