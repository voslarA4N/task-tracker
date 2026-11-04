<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Libraries\Export\CsvWriter;
use App\Libraries\Export\ExportTable;
use App\Libraries\Export\ReportTable;
use App\Libraries\Export\XlsxWriter;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Money;
use App\Libraries\Reporting\ReportCalculator;
use App\Libraries\Reporting\ReportPeriod;
use App\Libraries\Reporting\ReportResult;
use App\Libraries\Reporting\TurnoverCalculator;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Tests\Support\ReportFixtures;
use Tests\Support\TurnoverFixtures;
use Tests\Support\XlsxReader;

/**
 * D-05, NFR-04: prehlad = sucet vystavenych faktur = obrat (TurnoverCalculator) = hodnoty v CSV.
 *
 * @internal
 */
#[Group('integration')]
final class ReportTotalsMatchTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use CreditNoteFixtures;
    use IssueFixtures;
    use TurnoverFixtures;
    use ReportFixtures;

    private int $entity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        Services::injectMock('clock', new FrozenClock('2026-12-15T10:00:00+01:00'));
        $this->entity = $this->createEntity('SK', ['active_from' => '2025-01-01']);
        $a            = $this->createClient(['name' => 'Klient A']);
        $b            = $this->clientOutsideLimit('Klient B');

        $big = $this->reportInvoice($this->entity, $a, '2026-10-05', '1000.00');
        $pln = $this->reportInvoice($this->entity, $a, '2026-10-20', '100.00', ['total_entity_ccy' => '23.33', 'currency' => 'PLN', 'fx_rate' => '4.286', 'fx_source' => 'NBP', 'fx_date' => '2026-10-02']);
        $this->reportInvoice($this->entity, $b, '2026-10-12', '300.00', ['counts_toward_limit' => false]);
        $sep = $this->reportInvoice($this->entity, $a, '2026-09-10', '400.00');
        $this->reportInvoice($this->entity, $a, '2026-03-10', '150.00');
        $this->turnoverCreditNote($sep, '2026-10-25');
        $this->reportPayment($pln, '2026-10-21', '50.00');
        $this->reportPayment($big, '2026-11-02', '100.00');
        $voided = $this->reportPayment($big, '2026-11-03', '100.00');
        $this->voidPayment($voided);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function report(ReportPeriod $p): ReportResult
    {
        return (new ReportCalculator($this->rw()))->forPeriod($this->entity, $p);
    }

    private function sqlInvoiced(string $from, string $to): string
    {
        $row = $this->rw()->query(
            "SELECT COALESCE(SUM(total_entity_ccy), 0) AS t FROM invoices WHERE entity_id = :e: AND origin = 'issued' AND turnover_date BETWEEN :f: AND :t:",
            ['e' => $this->entity, 'f' => $from, 't' => $to],
        )->getRowArray();

        return Money::of((string) $row['t'])->toDecimalString();
    }

    /**
     * @return list<list<string>>
     */
    private function parse(string $csv): array
    {
        $csv   = substr($csv, 3);
        $lines = array_values(array_filter(explode("\n", $csv), static fn (string $l): bool => $l !== ''));

        return array_map(static fn (string $l): array => str_getcsv($l, ';', '"', ''), $lines);
    }

    private static function dot(string $v): string
    {
        return str_replace(',', '.', $v);
    }

    public function testMonthTotalsMatch(): void
    {
        $period = ReportPeriod::month('2026-10');
        $r      = $this->report($period);

        $this->assertSame($this->sqlInvoiced('2026-10-01', '2026-10-31'), $r->invoicedTotal);
        $this->assertSame(Money::sum(...array_map(static fn (array $c): Money => Money::of($c['invoiced']), $r->clients))->toDecimalString(), $r->invoicedTotal);
        $this->assertSame(Money::sum(...array_map(static fn (array $c): Money => Money::of($c['paid']), $r->clients))->toDecimalString(), $r->paidTotal);

        $turnover = (new TurnoverCalculator($this->rw()))->turnover($this->entity, '2026-10-01', '2026-10-31');
        $this->assertSame($turnover['total'], $r->countedTotal);
        $this->assertNotSame($r->invoicedTotal, $r->countedTotal);
    }

    public function testMonthCsvMatches(): void
    {
        $r    = $this->report(ReportPeriod::month('2026-10'));
        $rows = $this->parse(CsvWriter::render(ReportTable::month($r, 'sk')));
        $last = array_pop($rows);
        array_shift($rows);

        $this->assertSame($r->invoicedTotal, self::dot($last[1]));
        $this->assertSame($r->paidTotal, self::dot($last[2]));
        $this->assertSame($r->invoicedTotal, Money::sum(...array_map(static fn (array $c): Money => Money::of(self::dot($c[1])), $rows))->toDecimalString());
        $this->assertSame($r->paidTotal, Money::sum(...array_map(static fn (array $c): Money => Money::of(self::dot($c[2])), $rows))->toDecimalString());
    }

    public function testYearTotalsMatch(): void
    {
        $r = $this->report(ReportPeriod::year(2026));

        $this->assertSame($this->sqlInvoiced('2026-01-01', '2026-12-31'), $r->invoicedTotal);
        $this->assertSame(Money::sum(...array_map(static fn (array $m): Money => Money::of($m['invoiced']), $r->months))->toDecimalString(), $r->invoicedTotal);

        $turnover = (new TurnoverCalculator($this->rw()))->turnover($this->entity, '2026-01-01', '2026-12-31');
        $this->assertSame($turnover['total'], $r->countedTotal);
    }

    public function testYearCsvMatches(): void
    {
        $r    = $this->report(ReportPeriod::year(2026));
        $rows = $this->parse(CsvWriter::render(ReportTable::year($r, 'sk')));
        $last = array_pop($rows);
        array_shift($rows);
        $months = array_slice($rows, 0, 12);

        $this->assertCount(12, $months);
        $this->assertSame($r->invoicedTotal, self::dot($last[2]));
        $this->assertSame($r->invoicedTotal, Money::sum(...array_map(static fn (array $c): Money => Money::of(self::dot($c[2])), $months))->toDecimalString());
        $this->assertSame($r->paidTotal, Money::sum(...array_map(static fn (array $c): Money => Money::of(self::dot($c[3])), $months))->toDecimalString());
    }

    /**
     * @return array{rows: list<list<array{type: string, value: string, style: ?string}>>}
     */
    private function xlsx(ExportTable $t): array
    {
        $rows = XlsxReader::cells(XlsxWriter::render($t));
        array_shift($rows);

        return ['rows' => $rows];
    }

    public function testMonthXlsxMatches(): void
    {
        $r    = $this->report(ReportPeriod::month('2026-10'));
        $rows = $this->xlsx(ReportTable::month($r, 'sk'))['rows'];
        $last = array_pop($rows);

        $this->assertSame($r->invoicedTotal, Money::of($last[1]['value'])->toDecimalString());
        $this->assertSame($r->paidTotal, Money::of($last[2]['value'])->toDecimalString());
        $this->assertSame($r->invoicedTotal, Money::sum(...array_map(static fn (array $c): Money => Money::of($c[1]['value']), $rows))->toDecimalString());
    }

    public function testYearXlsxMatches(): void
    {
        $r      = $this->report(ReportPeriod::year(2026));
        $rows   = $this->xlsx(ReportTable::year($r, 'sk'))['rows'];
        $last   = array_pop($rows);
        $months = array_slice($rows, 0, 12);

        $this->assertSame($r->invoicedTotal, Money::of($last[2]['value'])->toDecimalString());
        $this->assertSame($r->paidTotal, Money::of($last[3]['value'])->toDecimalString());
        $this->assertSame($r->invoicedTotal, Money::sum(...array_map(static fn (array $c): Money => Money::of($c[2]['value']), $months))->toDecimalString());
    }
}
