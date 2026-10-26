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
final class TaskDetailSummaryTest extends AuthTestCase
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

    public function testSummaryAndParts(): void
    {
        $task   = $this->createTask($this->createClient());
        $locked = $this->createAllocation($task, '16.00', '2026-09');
        $this->lockAllocation($locked);
        $open = $this->createAllocation($task, '8.00', '2026-10');

        $html = $this->body($this->get('/tasks/' . $task));

        foreach (['quoted', 'billed', 'planned', 'remaining'] as $key) {
            $this->assertStringContainsString(esc(lang('Allocations.' . $key)), $html);
        }

        $nb = "\u{00A0}";
        $this->assertStringContainsString('40 h', $html);
        $this->assertStringContainsString('1' . $nb . '080,00' . $nb . '€', $html);
        $this->assertStringContainsString('432,00' . $nb . '€', $html);
        $this->assertStringContainsString('216,00' . $nb . '€', $html);
        $this->assertStringContainsString('work-seg-40', $html);
        $this->assertStringContainsString('work-seg-20', $html);
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('Vyfakturované 16 h', $html);
        $this->assertStringNotContainsString('/allocations/' . $locked . '/edit', $html);
        $this->assertStringContainsString('hx-get="/allocations/' . $open . '/edit"', $html);
        $this->assertStringContainsString(esc(lang('Allocations.lockedHint')), $html);
        $this->assertStringContainsString(esc(lang('Allocations.addButton')), $html);
        $this->assertStringNotContainsString('style="', $html);
        $this->assertStringNotContainsString('work-delta', $html);
    }

    public function testEmptyStateStillOffersAdd(): void
    {
        $task = $this->createTask($this->createClient());
        $html = $this->body($this->get('/tasks/' . $task));

        $this->assertStringContainsString(esc(lang('Allocations.emptyParts')), $html);
        $this->assertStringContainsString('hx-get="/tasks/' . $task . '/allocations/new"', $html);
        $this->assertStringContainsString(esc(lang('Allocations.addButton')), $html);
    }

    public function testDoneOverQuotedShowsDelta(): void
    {
        $task = $this->createTask($this->createClient(), ['status' => 'done', 'done_at' => '2026-10-20 10:00:00+02']);
        $this->createAllocation($task, '30.00', '2026-10');
        $this->createAllocation($task, '16.00', '2026-11');

        $html = $this->body($this->get('/tasks/' . $task));

        $this->assertStringContainsString('work-delta', $html);
        $this->assertStringContainsString('+6 h nad nacenené', $html);
        $this->assertStringContainsString(esc(lang('Allocations.overBadge')), $html);
    }
}
