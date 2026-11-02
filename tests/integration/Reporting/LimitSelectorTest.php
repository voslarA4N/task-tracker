<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Libraries\Reporting\LimitSelector;
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
final class LimitSelectorTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use CreditNoteFixtures;
    use IssueFixtures;
    use TurnoverFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner()->query('DELETE FROM turnover_limits');
    }

    protected function tearDown(): void
    {
        $this->owner()->query('DELETE FROM turnover_limits');
        parent::tearDown();
    }

    public function testExactYearAndThresholdRoundTrip(): void
    {
        $this->insertLimit('SK', 2026, 'A', '50000.00', 'EUR', 'calendar_year', '80,100');
        $this->insertLimit('SK', 2026, 'B', '62500.00', 'EUR', 'calendar_year', '100', true);
        $this->insertLimit('PL', 2026, 'C', '240000.00', 'PLN', 'pro_rata_first_year');

        $r = (new LimitSelector($this->rw()))->forCountry('SK', 2026);
        $this->assertNull($r['usedYear']);
        $this->assertSame(['A', 'B'], array_column($r['rows'], 'name'));
        $this->assertSame([80, 100], $r['rows'][0]['thresholds']);
        $this->assertSame([100], $r['rows'][1]['thresholds']);
        $this->assertFalse($r['rows'][0]['is_seed']);
        $this->assertTrue($r['rows'][1]['is_seed']);
        $this->assertSame('EUR', $r['rows'][0]['currency']);
        $this->assertSame('50000.00', $r['rows'][0]['amount']);
    }

    public function testFallbackYears(): void
    {
        $sel = new LimitSelector($this->rw());
        $this->insertLimit('SK', 2025, 'N', '1.00', 'EUR', 'calendar_year');
        $this->insertLimit('SK', 2027, 'V', '2.00', 'EUR', 'calendar_year');
        $this->assertSame(2025, $sel->forCountry('SK', 2026)['usedYear']);

        $this->owner()->query('DELETE FROM turnover_limits WHERE year = 2025');
        $r = (new LimitSelector($this->rw()))->forCountry('SK', 2026);
        $this->assertSame(2027, $r['usedYear']);
        $this->assertSame(['V'], array_column($r['rows'], 'name'));

        $this->owner()->query('DELETE FROM turnover_limits');
        $this->assertSame(['rows' => [], 'usedYear' => null], $sel->forCountry('SK', 2026));
    }

    public function testParseThresholds(): void
    {
        $this->assertSame([80, 100], LimitSelector::parseThresholds('80,100'));
        $this->assertSame([], LimitSelector::parseThresholds(''));
    }
}
