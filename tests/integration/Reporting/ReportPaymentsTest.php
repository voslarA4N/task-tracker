<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Money;
use App\Libraries\Reporting\ReportCalculator;
use App\Libraries\Reporting\ReportPeriod;
use App\Libraries\Reporting\ReportResult;
use Config\Services;
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
final class ReportPaymentsTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use CreditNoteFixtures;
    use IssueFixtures;
    use TurnoverFixtures;
    use ReportFixtures;

    private int $entity;
    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-12-31T10:00:00+01:00'));
        $this->resetMasterTables();
        $this->entity = $this->createEntity('SK', ['active_from' => '2025-01-01']);
        $this->client = $this->createClient(['name' => 'Klient A']);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function month(string $ym, ?int $entity = null): ReportResult
    {
        return (new ReportCalculator($this->rw()))->forPeriod($entity ?? $this->entity, ReportPeriod::month($ym));
    }

    private function paidOf(string $ym): string
    {
        return $this->month($ym)->paidTotal;
    }

    public function testPaymentIsAssignedToPaymentMonthAndClientAppearsWithZeroInvoiced(): void
    {
        $inv = $this->reportInvoice($this->entity, $this->client, '2026-10-05', '1000.00');
        $this->reportPayment($inv, '2026-11-05', '1000.00');

        $oct = $this->month('2026-10');
        $this->assertSame('0.00', $oct->paidTotal);
        $this->assertSame('1000.00', $oct->invoicedTotal);

        $nov = $this->month('2026-11');
        $this->assertSame('1000.00', $nov->paidTotal);
        $this->assertSame('0.00', $nov->invoicedTotal);
        $this->assertCount(1, $nov->clients);
        $this->assertSame('0.00', $nov->clients[0]['invoiced']);
        $this->assertSame('1000.00', $nov->clients[0]['paid']);
        $this->assertFalse($nov->isEmpty());
    }

    public function testPartialPaymentsSplitAcrossMonths(): void
    {
        $inv = $this->reportInvoice($this->entity, $this->client, '2026-10-05', '1000.00');
        $this->reportPayment($inv, '2026-10-20', '400.00');
        $this->reportPayment($inv, '2026-11-05', '600.00');

        $this->assertSame('400.00', $this->paidOf('2026-10'));
        $this->assertSame('600.00', $this->paidOf('2026-11'));
    }

    public function testVoidedPaymentIsIgnored(): void
    {
        $inv = $this->reportInvoice($this->entity, $this->client, '2026-10-05', '1000.00');
        $p1  = $this->reportPayment($inv, '2026-10-20', '400.00');
        $this->reportPayment($inv, '2026-10-25', '100.00');
        $this->voidPayment($p1);

        $this->assertSame('100.00', $this->paidOf('2026-10'));
    }

    public function testForeignCurrencyUsesStoredRatioAndSumsExactly(): void
    {
        $inv = $this->reportInvoice($this->entity, $this->client, '2026-10-05', '100.00', ['total_entity_ccy' => '23.33', 'currency' => 'PLN', 'fx_rate' => '4.286', 'fx_source' => 'NBP', 'fx_date' => '2026-10-02']);
        $this->reportPayment($inv, '2026-10-10', '33.33');
        $this->reportPayment($inv, '2026-11-10', '33.33');
        $this->reportPayment($inv, '2026-12-10', '33.34');

        $this->assertSame('7.78', $this->paidOf('2026-10'));
        $this->assertSame('7.77', $this->paidOf('2026-11'));
        $this->assertSame('7.78', $this->paidOf('2026-12'));

        $year = (new ReportCalculator($this->rw()))->forPeriod($this->entity, ReportPeriod::year(2026));
        $this->assertSame('23.33', $year->paidTotal);
    }

    public function testOtherEntityPaymentIsNotShown(): void
    {
        $other = $this->createEntity('SK', ['name_internal' => 'Druhy', 'active_from' => '2025-01-01']);
        $inv   = $this->reportInvoice($other, $this->client, '2026-10-05', '1000.00');
        $this->reportPayment($inv, '2026-10-06', '1000.00');

        $this->assertSame('0.00', $this->paidOf('2026-10'));
        $this->assertSame('1000.00', $this->month('2026-10', $other)->paidTotal);
    }

    public function testYearTotalsAgree(): void
    {
        $a = $this->reportInvoice($this->entity, $this->client, '2026-03-05', '1000.00');
        $b = $this->reportInvoice($this->entity, $this->createClient(['name' => 'Klient B', 'identifier_value' => '22222222']), '2026-04-05', '500.00');
        $this->reportPayment($a, '2026-04-01', '300.00');
        $this->reportPayment($a, '2026-07-01', '700.00');
        $this->reportPayment($b, '2026-05-01', '500.00');

        $year = (new ReportCalculator($this->rw()))->forPeriod($this->entity, ReportPeriod::year(2026));

        $this->assertSame('1500.00', $year->paidTotal);
        $this->assertSame('1500.00', $this->sum(array_column($year->months, 'paid')));
        $this->assertSame('1500.00', $this->sum(array_column($year->clients, 'paid')));
    }

    /**
     * @param list<string> $values
     */
    private function sum(array $values): string
    {
        return Money::sum(...array_map(static fn (string $v): Money => Money::of($v), $values))->toDecimalString();
    }
}
