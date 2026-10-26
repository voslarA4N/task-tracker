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
final class TaskListTest extends AuthTestCase
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
     * @return array{wip: int, done: int, billed: int, plain: int, client: int}
     */
    private function seed(): array
    {
        $client = $this->createClient();
        $doneAt = '2026-10-20 10:00:00+02';

        $wip = $this->createTask($client, ['title' => 'Listobox oprava']);

        $done = $this->createTask($client, ['title' => 'Zapracovana uloha', 'status' => 'done', 'done_at' => $doneAt]);
        $this->createAllocation($done, '8.00', '2026-10');

        $billed = $this->createTask($client, ['title' => 'Vyfakturovana uloha', 'status' => 'done', 'done_at' => $doneAt, 'hours' => '16.00']);
        $this->lockAllocation($this->createAllocation($billed, '16.00', '2026-09'));

        $plain = $this->createTask($client, ['title' => 'Hotova bez casti', 'status' => 'done', 'done_at' => $doneAt]);

        return ['wip' => $wip, 'done' => $done, 'billed' => $billed, 'plain' => $plain, 'client' => $client];
    }

    public function testEmptyState(): void
    {
        $html = $this->body($this->get('/tasks'));

        $this->assertStringContainsString('Aktuálne úlohy', $html);
        $this->assertStringContainsString('Žiadne aktuálne úlohy', $html);
        $this->assertStringContainsString(esc(lang('Tasks.emptyBody')), $html);
    }

    public function testDefaultListShowsCurrentTasksWithBadges(): void
    {
        $this->seed();
        $html = $this->body($this->get('/tasks'));

        $this->assertStringContainsString('Listobox oprava', $html);
        $this->assertStringContainsString('Zapracovana uloha', $html);
        $this->assertStringNotContainsString('Vyfakturovana uloha', $html);
        $this->assertStringNotContainsString('Hotova bez casti', $html);
        $this->assertStringContainsString('V práci', $html);
        $this->assertStringContainsString('Zapracované', $html);
        $this->assertStringContainsString('2 úloh', $html);
        $this->assertStringContainsString('€', $html);
        $this->assertStringNotContainsString('style="', $html);
    }

    public function testRowLinksToDetailAndEscapesTitle(): void
    {
        $client = $this->createClient();
        $id     = $this->createTask($client, ['title' => '<b>x</b>']);
        $html   = $this->body($this->get('/tasks'));

        $this->assertStringContainsString('href="/tasks/' . $id . '"', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function testStateFilters(): void
    {
        $this->seed();

        $billed = $this->body($this->get('/tasks?state=billed'));
        $this->assertStringContainsString('Vyfakturovana uloha', $billed);
        $this->assertStringNotContainsString('Listobox oprava', $billed);
        $this->assertStringContainsString('text-bg-secondary', $billed);

        $client = $this->createClient(['name' => 'Iny klient']);
        $part   = $this->createTask($client, ['title' => 'Ciastocna uloha', 'status' => 'done', 'done_at' => '2026-10-20 10:00:00+02']);
        $this->lockAllocation($this->createAllocation($part, '10.00', '2026-09'));
        $this->createAllocation($part, '10.00', '2026-10');

        $partial = $this->body($this->get('/tasks?state=partial'));
        $this->assertStringContainsString('Ciastocna uloha', $partial);
        $this->assertStringContainsString('work-progress', $partial);
        $this->assertStringContainsString('Čiastočne', $partial);
        $this->assertStringNotContainsString('Vyfakturovana uloha', $partial);

        $all = $this->body($this->get('/tasks?state=all'));

        foreach (['Listobox oprava', 'Zapracovana uloha', 'Vyfakturovana uloha', 'Hotova bez casti'] as $title) {
            $this->assertStringContainsString($title, $all);
        }
    }

    public function testClientMonthAndTextFilters(): void
    {
        $ids   = $this->seed();
        $other = $this->createClient(['name' => 'Druhy klient']);
        $this->createTask($other, ['title' => 'Cudzia uloha']);

        $byClient = $this->body($this->get('/tasks?client=' . $other));
        $this->assertStringContainsString('Cudzia uloha', $byClient);
        $this->assertStringNotContainsString('Listobox oprava', $byClient);

        $byMonth = $this->body($this->get('/tasks?month=2026-10'));
        $this->assertStringContainsString('Zapracovana uloha', $byMonth);
        $this->assertStringNotContainsString('Listobox oprava', $byMonth);

        $byText = $this->body($this->get('/tasks?q=LISTOBOX'));
        $this->assertStringContainsString('Listobox oprava', $byText);
        $this->assertStringNotContainsString('Zapracovana uloha', $byText);
        $this->assertStringContainsString('value="LISTOBOX"', $byText);
        $this->assertGreaterThan(0, $ids['client']);

        $none = $this->body($this->get('/tasks?q=%25'));
        $this->assertStringContainsString('Nič nezodpovedá filtru', $none);
    }

    public function testHtmxReturnsFragmentOnly(): void
    {
        $this->seed();
        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/tasks?q=listobox');
        $html   = $this->body($result);

        $this->assertStringContainsString('id="task-list"', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringContainsString('Listobox oprava', $html);
        $this->assertStringContainsString('HX-Request', $result->response()->getHeaderLine('Vary'));
    }

    public function testFilterFormAttributes(): void
    {
        $this->seed();
        $html = $this->body($this->get('/tasks?state=billed&q=abc'));

        $this->assertStringContainsString('hx-get="/tasks"', $html);
        $this->assertStringContainsString('hx-target="#task-list"', $html);
        $this->assertStringContainsString('hx-push-url="true"', $html);
        $this->assertStringContainsString('method="get"', $html);

        foreach (['Aktuálne', 'V práci', 'Zapracované', 'Čiastočne vyfakturované', 'Vyfakturované', 'Všetky'] as $label) {
            $this->assertStringContainsString('>' . $label . '</option>', $html);
        }

        $this->assertMatchesRegularExpression('/<option value="billed"\s+selected/', $html);
        $this->assertStringContainsString('Filtre (2)', $html);
        $this->assertStringContainsString('href="/tasks"', $html);
        $this->assertStringContainsString('Vymazať filtre', $html);
    }

    public function testQueryIsEscapedInForm(): void
    {
        $html = $this->body($this->get('/tasks?q=' . rawurlencode('"><script>alert(1)</script>')));

        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function testInvalidParametersFallBackToDefaults(): void
    {
        $this->seed();
        $result = $this->get('/tasks?state=evil&month=2026-13&client=abc');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString('Listobox oprava', $html);
        $this->assertStringNotContainsString('Vyfakturovana uloha', $html);
    }

    public function testNewTaskLinks(): void
    {
        $html = $this->body($this->get('/tasks'));

        $this->assertStringContainsString('class="work-fab"', $html);
        $this->assertStringContainsString('aria-label="Nová úloha"', $html);
        $this->assertStringContainsString('href="/tasks/new"', $html);
        $this->assertStringContainsString('d-none d-md-inline-flex', $html);
    }

    public function testTruncatedHint(): void
    {
        $client = $this->createClient();

        for ($i = 0; $i < 201; $i++) {
            $this->createTask($client, ['title' => 'Uloha ' . $i]);
        }

        $html = $this->body($this->get('/tasks'));

        $this->assertStringContainsString('Zobrazených prvých 200. Zúžte filter.', $html);
    }
}
