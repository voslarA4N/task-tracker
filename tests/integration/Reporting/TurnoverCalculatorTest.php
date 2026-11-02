<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Libraries\Reporting\TurnoverCalculator;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Tests\Support\TurnoverFixtures;

/**
 * @internal
 */
#[Group('integration')]
final class TurnoverCalculatorTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use CreditNoteFixtures;
    use IssueFixtures;
    use TurnoverFixtures;

    private int $entity;
    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        $this->owner()->query('DELETE FROM turnover_limits');
        $this->entity = $this->createEntity('SK', ['active_from' => '2025-01-01']);
        $this->client = $this->createClient();
    }

    protected function tearDown(): void
    {
        $this->owner()->query('DELETE FROM turnover_limits');
        $this->resetMasterTables();
        parent::tearDown();
    }

    private function calc(): TurnoverCalculator
    {
        return new TurnoverCalculator($this->rw());
    }

    public function testCalendarBoundaries(): void
    {
        $this->turnoverInvoice($this->entity, $this->client, '2025-12-31', '1000.00');
        $this->turnoverInvoice($this->entity, $this->client, '2026-01-01', '2000.00');
        $this->turnoverInvoice($this->entity, $this->client, '2026-12-31', '3000.00');
        $this->turnoverInvoice($this->entity, $this->client, '2027-01-01', '4000.00');

        $this->assertSame(['total' => '5000.00', 'invoices' => 2, 'credit_notes' => 0], $this->calc()->turnover($this->entity, '2026-01-01', '2026-12-31'));
    }

    public function testCreditNoteNetsInOriginalPeriod(): void
    {
        $id = $this->turnoverInvoice($this->entity, $this->client, '2026-12-20', '10000.00');
        $this->turnoverCreditNote($id, '2027-01-15');

        $this->assertSame(['total' => '0.00', 'invoices' => 1, 'credit_notes' => 1], $this->calc()->turnover($this->entity, '2026-01-01', '2026-12-31'));
        $this->assertSame(['total' => '0.00', 'invoices' => 0, 'credit_notes' => 0], $this->calc()->turnover($this->entity, '2027-01-01', '2027-12-31'));
    }

    public function testExcludedAndExternal(): void
    {
        $this->turnoverInvoice($this->entity, $this->client, '2026-03-10', '500.00', ['counts_toward_limit' => false]);

        $task  = $this->createTask($this->client, ['rate' => '100.00']);
        $alloc = $this->createAllocation($task, '5.00', '2026-03', ['rate' => '100.00']);
        $this->insertExternalInvoice($this->client, '2026-03', [$alloc]);

        $other = $this->createEntity('SK', ['name_internal' => 'Iny subjekt', 'active_from' => '2025-01-01']);
        $this->turnoverInvoice($other, $this->client, '2026-03-10', '700.00');

        $this->assertSame(['total' => '0.00', 'invoices' => 0, 'credit_notes' => 0], $this->calc()->turnover($this->entity, '2026-01-01', '2026-12-31'));
        $this->assertSame('700.00', $this->calc()->turnover($other, '2026-01-01', '2026-12-31')['total']);
    }

    public function testClientFlagChangeDoesNotMatter(): void
    {
        $this->turnoverInvoice($this->entity, $this->client, '2026-03-10', '800.00');
        $this->owner()->query('UPDATE clients SET counts_toward_limit = false WHERE id = :c:', ['c' => $this->client]);

        $this->assertSame('800.00', $this->calc()->turnover($this->entity, '2026-01-01', '2026-12-31')['total']);
    }

    public function testForeignCurrencyUsesEntityAmount(): void
    {
        $this->turnoverInvoice($this->entity, $this->client, '2026-03-10', '4300.00', ['currency' => 'PLN', 'total_entity_ccy' => '1000.00', 'fx_rate' => '4.30000000', 'fx_source' => 'NBP', 'fx_date' => '2026-03-09']);

        $this->assertSame('1000.00', $this->calc()->turnover($this->entity, '2026-01-01', '2026-12-31')['total']);
    }

    public function testIgnoresRatesAndSnapshot(): void
    {
        $this->turnoverInvoice($this->entity, $this->client, '2026-03-10', '1000.00', ['snapshot' => '{"schema":1,"totals":{"total":"999999.99"},"fx":{"total":"999999.99"}}']);
        $before = $this->calc()->turnover($this->entity, '2026-01-01', '2026-12-31');

        $this->owner()->query(
            "INSERT INTO exchange_rate_cache (source, currency, bound_date, valid_for, rate, rate_published, direction, fetched_at)
             VALUES ('NBP', 'EUR', '2026-03-10', '2026-03-10', 4.1, '4.1', 'multiply', now())",
        );
        $withRate = $this->calc()->turnover($this->entity, '2026-01-01', '2026-12-31');
        $this->owner()->query('DELETE FROM exchange_rate_cache');
        $without = $this->calc()->turnover($this->entity, '2026-01-01', '2026-12-31');

        $this->assertSame('1000.00', $before['total']);
        $this->assertSame($before, $withRate);
        $this->assertSame($before, $without);
    }

    public function testOpeningTurnover(): void
    {
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $this->insertOpening($this->entity, 2026, '3000.00');
        $this->turnoverInvoice($this->entity, $this->client, '2026-03-10', '40000.00');
        $this->turnoverInvoice($this->entity, $this->client, '2026-04-10', '1230.00');

        $limits = $this->calc()->forEntityId($this->entity, '2026-11-15');
        $this->assertNotNull($limits);
        $s = $limits->statuses[0];
        $this->assertNull($s->error);
        $this->assertSame('41230.00', $s->documentTurnover);
        $this->assertSame('3000.00', $s->openingTurnover);
        $this->assertSame('44230.00', $s->total);
        $this->assertSame('88.5', $s->percent);
        $this->assertSame('warn', $s->level);
        $this->assertSame('5770.00', $s->remaining);
        $this->assertSame(2, $s->invoiceCount);
        $this->assertSame(0, $s->creditNoteCount);
        $this->assertFalse($limits->noLimit);
        $this->assertNull($limits->usedYear);
    }

    public function testRollingAddsOpeningOfEveryTouchedYear(): void
    {
        $this->insertLimit('SK', 2026, 'Kĺzavý rok', '49790.00', 'EUR', 'rolling_12m');
        $this->insertOpening($this->entity, 2025, '1000.00');
        $this->insertOpening($this->entity, 2026, '2000.00');

        $s = $this->calc()->forEntityId($this->entity, '2026-11-15')->statuses[0];
        $this->assertSame('3000.00', $s->openingTurnover);
        $this->assertSame([2025, 2026], $s->openingYears);
    }

    public function testProRataFirstYear(): void
    {
        $pl = $this->createEntity('PL', ['active_from' => '2026-07-15']);
        $this->insertLimit('PL', 2026, 'Zwolnienie', '240000.00', 'PLN', 'pro_rata_first_year');

        $s = $this->calc()->forEntityId($pl, '2026-10-01')->statuses[0];
        $this->assertSame('240000.00', $s->baseLimit);
        $this->assertSame('111780.82', $s->limit);
        $this->assertSame(170, $s->proRataDays);
    }

    public function testCurrencyMismatch(): void
    {
        $this->insertLimit('SK', 2026, 'V zlotych', '50000.00', 'PLN', 'calendar_year');
        $this->turnoverInvoice($this->entity, $this->client, '2026-03-10', '1000.00');

        $s = $this->calc()->forEntityId($this->entity, '2026-11-15')->statuses[0];
        $this->assertSame('currency_mismatch', $s->error);
        $this->assertSame('0.00', $s->total);
        $this->assertSame('0.0', $s->percent);
        $this->assertTrue($s->isAlert());
    }

    public function testStrictestFirst(): void
    {
        $this->insertLimit('SK', 2026, 'A kalendárny', '50000.00', 'EUR', 'calendar_year');
        $this->insertLimit('SK', 2026, 'B kĺzavý', '49790.00', 'EUR', 'rolling_12m');
        $this->turnoverInvoice($this->entity, $this->client, '2026-03-10', '20000.00');

        $limits = $this->calc()->forEntityId($this->entity, '2026-11-15');
        $this->assertCount(2, $limits->statuses);
        $this->assertSame('rolling_12m', $limits->statuses[0]->method);
        $this->assertSame('rolling_12m', $limits->strictest()?->method);
        $this->assertSame('calendar_year', $limits->statuses[1]->method);
    }

    public function testFallbackYearAndNoLimit(): void
    {
        $this->insertLimit('SK', 2025, 'Staré', '50000.00', 'EUR', 'calendar_year');
        $limits = $this->calc()->forEntityId($this->entity, '2026-11-15');
        $this->assertSame(2025, $limits->usedYear);
        $this->assertSame(2026, $limits->refYear);
        $this->assertSame(2026, $limits->statuses[0]->fallbackFromYear);

        $this->owner()->query('DELETE FROM turnover_limits');
        $none = $this->calc()->forEntityId($this->entity, '2026-11-15');
        $this->assertTrue($none->noLimit);
        $this->assertSame([], $none->statuses);
        $this->assertNull($none->strictest());
        $this->assertNull($this->calc()->forEntityId(999999, '2026-11-15'));
    }

    public function testForActiveEntitiesSkipsEnded(): void
    {
        $ended = $this->createEntity('SK', ['name_internal' => 'Ukonceny', 'active_from' => '2024-01-01', 'active_to' => '2025-06-30']);
        $this->insertLimit('SK', 2026, 'Limit', '50000.00', 'EUR', 'calendar_year');

        $ids = array_map(static fn ($l): int => $l->entityId, $this->calc()->forActiveEntities('2026-11-15'));
        $this->assertContains($this->entity, $ids);
        $this->assertNotContains($ended, $ids);
    }
}
