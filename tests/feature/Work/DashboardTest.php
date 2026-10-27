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
final class DashboardTest extends AuthTestCase
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

    private function seedEur(): int
    {
        $client = $this->createClient();
        $doneAt = '2026-10-20 10:00:00+02';

        $done = $this->createTask($client, ['title' => 'Zapracovana A', 'status' => 'done', 'done_at' => $doneAt, 'hours' => '9.00']);
        $this->createAllocation($done, '8.00', '2026-10');
        $this->createAllocation($done, '1.00', '2026-10');

        $this->createTask($client, ['title' => 'Rozpracovana B', 'hours' => '40.00']);

        $partial = $this->createTask($client, ['title' => 'Ciastocna C', 'hours' => '10.00']);
        $this->lockAllocation($this->createAllocation($partial, '4.00', '2026-09'));
        $this->createAllocation($partial, '2.00', '2026-11');

        return $client;
    }

    public function testEmptyState(): void
    {
        $html = $this->body($this->get('/'));

        $this->assertStringContainsString('Október 2026', $html);
        $this->assertStringContainsString('Zatiaľ nič na fakturáciu', $html);
        $this->assertStringContainsString('október 2026', $html);
    }

    public function testSubtitleShowsLastClientAndRate(): void
    {
        $this->seedEur();
        $html = $this->body($this->get('/'));

        $this->assertStringContainsString('All4Net', $html);
        $this->assertStringContainsString('sadzba', $html);
        $this->assertStringContainsString('27,00', $html);
    }

    public function testTilesAndGroups(): void
    {
        $this->seedEur();
        $html = $this->body($this->get('/'));

        $this->assertStringContainsString('Na fakturáciu tento mesiac', $html);
        $this->assertStringContainsString('243,00', $html);
        $this->assertStringContainsString('9 h zapracovaných', $html);
        // WR-02: dlazdica Na fakturaciu vedie na zoznam s rovnakou podmienkou (group=due), WIP na vsetky in_progress.
        $this->assertStringContainsString('/tasks?group=due&amp;month=2026-10', $html);
        $this->assertStringNotContainsString('state=done', $html);
        $this->assertStringContainsString('nacenených', $html);
        $this->assertStringContainsString('/tasks?state=in_progress', $html);

        $done    = strpos($html, 'Zapracované, do faktúry');
        $partial = strpos($html, 'Čiastočne vyfakturované');
        $wip     = strrpos($html, 'V práci');
        $this->assertNotFalse($done);
        $this->assertNotFalse($partial);
        $this->assertNotFalse($wip);
        $this->assertLessThan($partial, $done);
        $this->assertLessThan($wip, $partial);
        $this->assertStringContainsString('work-progress', $html);
        $this->assertStringContainsString('Okt', $html);
        $this->assertStringContainsString('Zapracovana A', $html);
    }

    public function testShowAllLinksUseGroupDrillDown(): void
    {
        $client = $this->createClient();

        for ($i = 0; $i < 11; $i++) {
            $this->createTask($client, ['title' => 'W' . $i]);
        }

        $html = $this->body($this->get('/?client=' . $client));
        $this->assertStringContainsString('/tasks?group=in_progress&amp;month=2026-10&amp;client=' . $client, $html);

        $list = $this->body($this->get('/tasks?group=in_progress&month=2026-10&client=' . $client));
        $this->assertStringContainsString(esc(lang('Tasks.listMeta', [11])), $list);
        $this->assertStringContainsString(esc(lang('Tasks.groupFilter', [lang('Dashboard.groupInProgress')])), $list);
    }

    public function testEmptyGroupsAreNotRendered(): void
    {
        $client = $this->createClient();
        $this->createTask($client, ['title' => 'Len v praci']);
        $html = $this->body($this->get('/'));

        $this->assertStringNotContainsString('Zapracované, do faktúry', $html);
        $this->assertStringNotContainsString('Čiastočne vyfakturované', $html);
        $this->assertStringContainsString('Len v praci', $html);
    }

    public function testTilesPerCurrencyNeverSummed(): void
    {
        $this->seedEur();
        $pln  = $this->createClient(['name' => 'Polak', 'currency' => 'PLN', 'country' => 'PL', 'identifier_value' => '999']);
        $task = $this->createTask($pln, ['title' => 'PLN uloha', 'status' => 'done', 'done_at' => '2026-10-20 10:00:00+02', 'hours' => '10.00']);
        $this->createAllocation($task, '10.00', '2026-10');

        $html = $this->body($this->get('/?client=all'));

        $this->assertStringContainsString('Na fakturáciu tento mesiac (EUR)', $html);
        $this->assertStringContainsString('Na fakturáciu tento mesiac (PLN)', $html);
        $this->assertStringContainsString('243,00', $html);
        $this->assertStringNotContainsString('513,00', $html);
        $this->assertStringContainsString('Všetci klienti', $html);
    }

    public function testMonthParameter(): void
    {
        $this->seedEur();
        $html = $this->body($this->get('/?month=2026-11&client=all'));

        $this->assertStringContainsString('November 2026', $html);
        $this->assertStringContainsString('54,00', $html);
    }

    public function testMonthSwitchCanReturnFromCustomMonthToPreset(): void
    {
        // WR-03: predvyplneny month_other nesmie prebit zvoleny predvoleny mesiac.
        $this->seedEur();

        $custom = $this->body($this->get('/?month=other&month_other=2026-05&client=all'));
        $this->assertStringContainsString('Máj 2026', $custom);
        $this->assertMatchesRegularExpression('#id="dm-other-choice" value="other" checked#', $custom);
        $this->assertStringContainsString('value="2026-05"', $custom);

        $back = $this->body($this->get('/?month=2026-10&month_other=2026-05&client=all'));
        $this->assertStringContainsString('243,00', $back);
        $this->assertMatchesRegularExpression('#id="dm-2026-10" value="2026-10" checked#', $back);
        $this->assertDoesNotMatchRegularExpression('#id="dm-other-choice" value="other" checked#', $back);

        // Stary odkaz bez volby mesiaca stale funguje.
        $this->assertStringContainsString('November 2026', $this->body($this->get('/?month_other=2026-11&client=all')));
    }

    public function testClientParameterScopesData(): void
    {
        $this->seedEur();
        $other = $this->createClient(['name' => 'Iny', 'identifier_value' => '555']);
        $this->createTask($other, ['title' => 'Cudzia uloha']);

        $html = $this->body($this->get('/?client=' . $other));

        $this->assertStringContainsString('Cudzia uloha', $html);
        $this->assertStringNotContainsString('Rozpracovana B', $html);
    }

    public function testGroupLimitShowsAllLink(): void
    {
        $client = $this->createClient();

        for ($i = 1; $i <= 12; $i++) {
            $this->createTask($client, ['title' => 'WIP ' . $i]);
        }
        $html = $this->body($this->get('/'));

        $this->assertSame(10, substr_count($html, 'class="work-task"'));
        $this->assertStringContainsString('Zobraziť všetky úlohy', $html);
    }

    public function testFragment(): void
    {
        $this->seedEur();
        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/fragments/dashboard?month=2026-10&client=all');
        $html   = $this->body($result);

        $this->assertStringContainsString('id="dashboard-body"', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringContainsString('hx-trigger="allocationChanged from:body"', $html);
        $this->assertStringContainsString('hx-get="/fragments/dashboard?month=2026-10&amp;client=all"', $html);
        $this->assertStringContainsString('HX-Request', $result->response()->getHeaderLine('Vary'));
    }

    public function testSwitchSheetFormAndNavigation(): void
    {
        $this->seedEur();
        $html = $this->body($this->get('/'));

        $this->assertStringContainsString('data-bs-toggle="offcanvas"', $html);
        $this->assertStringContainsString('id="dashboard-switch"', $html);
        $this->assertStringContainsString('name="month"', $html);
        $this->assertStringContainsString('name="client"', $html);
        $this->assertStringContainsString('Všetci', $html);
        $this->assertStringContainsString('class="work-fab" href="/tasks/new"', $html);
        $this->assertMatchesRegularExpression('#<a href="/" aria-current="page">#', $html);
        $this->assertMatchesRegularExpression('#<a href="/tasks">#', $html);
        $this->assertMatchesRegularExpression('#<a href="/invoices">#', $html);
    }

    public function testNoInlineStyleNorDemoLink(): void
    {
        $this->seedEur();
        $html = $this->body($this->get('/'));

        $this->assertStringNotContainsString('style="', $html);
        $this->assertStringNotContainsString('href="/demo"', $html);
    }
}
