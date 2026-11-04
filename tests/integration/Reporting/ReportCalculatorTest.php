<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Libraries\Platform\Money;
use App\Libraries\Reporting\ReportCalculator;
use App\Libraries\Reporting\ReportPeriod;
use App\Libraries\Reporting\TurnoverCalculator;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Tests\Support\ReportFixtures;
use Tests\Support\TurnoverFixtures;

/**
 * @internal
 */
#[Group('integration')]
final class ReportCalculatorTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use CreditNoteFixtures;
    use IssueFixtures;
    use TurnoverFixtures;
    use ReportFixtures;

    private int $entity;
    private int $clientA;
    private int $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        $this->entity  = $this->createEntity('SK', ['active_from' => '2025-01-01']);
        $this->clientA = $this->createClient(['name' => 'Klient A']);
        $this->clientB = $this->createClient(['name' => 'Klient B', 'identifier_value' => '22222222']);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        parent::tearDown();
    }

    private function calc(): ReportCalculator
    {
        return new ReportCalculator($this->rw());
    }

    public function testBreakdownByClientSortedByInvoicedDesc(): void
    {
        $this->reportInvoice($this->entity, $this->clientA, '2026-10-05', '1000.00');
        $this->reportInvoice($this->entity, $this->clientA, '2026-10-20', '80.00');
        $this->reportInvoice($this->entity, $this->clientB, '2026-10-12', '500.00');

        $r = $this->calc()->forPeriod($this->entity, ReportPeriod::month('2026-10'));

        $this->assertSame('EUR', $r->currency);
        $this->assertCount(2, $r->clients);
        $this->assertSame('Klient A', $r->clients[0]['client_name']);
        $this->assertSame('1080.00', $r->clients[0]['invoiced']);
        $this->assertSame('Klient B', $r->clients[1]['client_name']);
        $this->assertSame('500.00', $r->clients[1]['invoiced']);
        $this->assertSame('1580.00', $r->invoicedTotal);
        $this->assertSame(3, $r->invoiceCount);
        $this->assertFalse($r->isEmpty());
    }

    public function testUsesTurnoverDateNotIssueDate(): void
    {
        $this->reportInvoice($this->entity, $this->clientA, '2026-09-30', '700.00');
        $this->reportInvoice($this->entity, $this->clientA, '2026-10-01', '100.00');

        $r = $this->calc()->forPeriod($this->entity, ReportPeriod::month('2026-10'));
        $this->assertSame('100.00', $r->invoicedTotal);
    }

    public function testCreditNoteNetsInMonthOfItsDeliveryDate(): void
    {
        // Dobropis dedi datum dodania povodnej faktury (CreditNoteIssuer), preto odpocita v jej mesiaci (D-01).
        $id = $this->reportInvoice($this->entity, $this->clientA, '2026-10-10', '1000.00');
        $this->turnoverCreditNote($id, '2026-11-15');

        $oct = $this->calc()->forPeriod($this->entity, ReportPeriod::month('2026-10'));
        $nov = $this->calc()->forPeriod($this->entity, ReportPeriod::month('2026-11'));

        $this->assertSame('0.00', $oct->invoicedTotal);
        $this->assertSame(1, $oct->invoiceCount);
        $this->assertSame(1, $oct->creditNoteCount);
        $this->assertTrue($nov->isEmpty());
    }

    public function testCountedDiffersFromInvoicedForClientOutsideLimit(): void
    {
        $outside = $this->clientOutsideLimit();
        $this->reportInvoice($this->entity, $this->clientA, '2026-10-05', '1000.00');
        $this->reportInvoice($this->entity, $outside, '2026-10-06', '300.00', ['counts_toward_limit' => false]);

        $r = $this->calc()->forPeriod($this->entity, ReportPeriod::month('2026-10'));

        $this->assertSame('1300.00', $r->invoicedTotal);
        $this->assertSame('1000.00', $r->countedTotal);

        $turnover = (new TurnoverCalculator($this->rw()))->turnover($this->entity, '2026-10-01', ReportPeriod::month('2026-10')->lastDay());
        $this->assertSame($turnover['total'], $r->countedTotal);
    }

    public function testOtherEntityIsIgnored(): void
    {
        $other = $this->createEntity('SK', ['name_internal' => 'Iny subjekt', 'active_from' => '2025-01-01']);
        $this->reportInvoice($other, $this->clientA, '2026-10-05', '900.00');
        $this->reportInvoice($this->entity, $this->clientA, '2026-10-05', '100.00');

        $this->assertSame('100.00', $this->calc()->forPeriod($this->entity, ReportPeriod::month('2026-10'))->invoicedTotal);
        $this->assertSame('900.00', $this->calc()->forPeriod($other, ReportPeriod::month('2026-10'))->invoicedTotal);
    }

    public function testExternalInvoiceIsExcluded(): void
    {
        $task  = $this->createTask($this->clientA, ['rate' => '100.00']);
        $alloc = $this->createAllocation($task, '5.00', '2026-10', ['rate' => '100.00']);
        $this->insertExternalInvoice($this->clientA, '2026-10', [$alloc]);

        $r = $this->calc()->forPeriod($this->entity, ReportPeriod::month('2026-10'));
        $this->assertSame('0.00', $r->invoicedTotal);
        $this->assertTrue($r->isEmpty());
    }

    public function testYearHasTwelveMonthsAndSumsMatch(): void
    {
        $this->reportInvoice($this->entity, $this->clientA, '2026-02-10', '200.00');
        $this->reportInvoice($this->entity, $this->clientB, '2026-10-10', '300.00');

        $r = $this->calc()->forPeriod($this->entity, ReportPeriod::year(2026));

        $this->assertCount(12, $r->months);
        $this->assertSame('2026-01', $r->months[0]['month']);
        $this->assertSame('0.00', $r->months[0]['invoiced']);
        $this->assertSame('200.00', $r->months[1]['invoiced']);
        $this->assertSame('300.00', $r->months[9]['invoiced']);
        $this->assertSame('500.00', $r->invoicedTotal);

        $sum = Money::sum(...array_map(static fn (array $m): Money => Money::of($m['invoiced']), $r->months));

        $this->assertSame($r->invoicedTotal, $sum->toDecimalString());
    }

    public function testEmptyPeriod(): void
    {
        $r = $this->calc()->forPeriod($this->entity, ReportPeriod::month('2026-10'));
        $this->assertTrue($r->isEmpty());
        $this->assertSame([], $r->clients);
        $this->assertSame('0.00', $r->invoicedTotal);
        $this->assertSame('0.00', $r->countedTotal);
    }
}
