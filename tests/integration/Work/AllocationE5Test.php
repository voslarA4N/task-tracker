<?php

declare(strict_types=1);

namespace Tests\Integration\Work;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Hours;
use App\Libraries\Work\AllocationService;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * Kriterium PRD E5: uloha 200 h rozlozena do 4 mesiacov.
 *
 * @internal
 */
#[Group('integration')]
final class AllocationE5Test extends PgTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    public function testTwoHundredHoursInFourMonths(): void
    {
        $service = new AllocationService($this->rw(), new AuditLogger($this->rw()));
        $task    = $this->createTask($this->createClient(), ['hours' => '200.00']);

        foreach (['2026-10', '2026-11', '2026-12', '2027-01'] as $month) {
            $service->add($task, ['hours' => '50.00', 'billing_month' => $month]);
        }

        $list = $service->listForTask($task);
        $this->assertSame(['2026-10', '2026-11', '2026-12', '2027-01'], array_column($list, 'billing_month'));

        $sum = '0.00';

        foreach ($list as $row) {
            $sum = Hours::plus($sum, $row['hours']);
        }

        $this->assertSame('200.00', $sum);

        // Precerpanie nie je blokovane.
        $service->add($task, ['hours' => '6.00', 'billing_month' => '2027-01']);
        $view = $this->owner()->query('SELECT allocated_hours FROM task_billing_v WHERE task_id = ?', [$task])->getRow();
        $this->assertSame('206.00', $view->allocated_hours);
    }
}
