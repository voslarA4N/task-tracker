<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\InvoiceDraftService;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class InvoiceDraftServiceTest extends PgTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function service(): InvoiceDraftService
    {
        return new InvoiceDraftService($this->rw());
    }

    private function counts(): string
    {
        return json_encode($this->owner()->query('SELECT (SELECT count(*) FROM invoices) AS i, (SELECT count(*) FROM invoice_counters) AS c, (SELECT count(*) FROM audit_log) AS a')->getRowArray(), JSON_THROW_ON_ERROR);
    }

    public function testDefaultMonthIsOldestUnlockedOrPrevious(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['10.00', '2026-09']]]);
        $this->assertSame('2026-09', $this->service()->defaultMonth($s['client']));

        $empty = $this->createClient(['name' => 'Bez casti']);
        $this->assertSame('2026-09', $this->service()->defaultMonth($empty));
    }

    public function testDefaultDates(): void
    {
        $s = $this->issueSetup();
        $d = $this->service()->draft($s['client'], '2026-10', null, null, null, null);

        $this->assertSame('2026-10-27', $d->issueDate);
        $this->assertSame('2026-10-31', $d->deliveryDate);
        $this->assertSame('2026-11-10', $d->dueDate);
        $this->assertSame($s['entity'], $d->entityId);
        $this->assertTrue($d->canIssue);

        $c30 = $this->createClient(['name' => 'Tridsat', 'due_days' => 30]);
        $t   = $this->createTask($c30);
        $this->createAllocation($t, '1.00', '2026-10');
        $this->assertSame('2026-11-26', $this->service()->draft($c30, '2026-10', null, null, null, null)->dueDate);
    }

    public function testDraftMatchesIssueAndWritesNothing(): void
    {
        $s      = $this->issueSetup(['parts' => [['10.50', '2026-10'], ['3.25', '2026-10']]]);
        $before = $this->counts();
        $d      = $this->service()->draft($s['client'], '2026-10', $s['entity'], null, null, null);

        $this->assertSame($before, $this->counts());
        $this->assertNotNull($d->snapshot);
        $this->assertNull($d->snapshot['number']);
        $this->assertSame(2, $d->lineCount);

        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $d->partIds, ['previewToken' => $d->previewToken]));

        $this->assertSame($d->total, $issued->total);
    }

    public function testZbpAmountWarningAboveLimit(): void
    {
        $below = $this->issueSetup(['country' => 'PL']);
        $d     = $this->service()->draft($below['client'], '2026-10', $below['entity'], null, null, null);
        $this->assertSame('1080.00', $d->total);
        $this->assertFalse($d->zbpAmountWarning);

        $this->resetMasterTables();
        $above = $this->issueSetup(['country' => 'PL', 'parts' => [['40.00', '2026-10', ['rate' => '250.00']]]]);
        $d     = $this->service()->draft($above['client'], '2026-10', $above['entity'], null, null, null);
        $this->assertSame('10000.00', $d->total);
        $this->assertTrue($d->zbpAmountWarning);
        $this->assertTrue($d->canIssue);

        $cash = $this->service()->draft($above['client'], '2026-10', $above['entity'], null, null, null, 'cash');
        $this->assertFalse($cash->zbpAmountWarning);
    }

    public function testEndedEntityIsNotOffered(): void
    {
        $s     = $this->issueSetup();
        $ended = $this->createEntity('SK', ['active_to' => '2026-10-15', 'name_internal' => 'Stara']);

        $d = $this->service()->draft($s['client'], '2026-10', $ended, null, null, null);

        $this->assertSame([$s['entity']], array_column($d->entities, 'id'));
        $this->assertSame($s['entity'], $d->entityId);
    }

    public function testCurrencyMismatchIsReportedWithoutBuild(): void
    {
        $s = $this->issueSetup();
        $this->owner()->query("UPDATE clients SET currency = 'PLN' WHERE id = :c:", ['c' => $s['client']]);
        $this->owner()->query("UPDATE billing_allocations SET currency = 'PLN' WHERE id = :p:", ['p' => $s['parts'][0]]);

        $d = $this->service()->draft($s['client'], '2026-10', null, null, null, null);

        $this->assertCount(1, $d->errors);
        $this->assertSame('currency_mismatch', $d->errors[0]['reason']);
        $this->assertFalse($d->canIssue);
        $this->assertNull($d->snapshot);
        $this->assertSame(1, $d->lineCount);
    }

    public function testLockedPartsAndBackdate(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.00', '2026-10']]]);
        $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]));

        $d = $this->service()->draft($s['client'], '2026-10', $s['entity'], '2026-10-20', null, null);

        $this->assertTrue($d->hasLockedInMonth);
        $this->assertSame([$s['parts'][1]], $d->partIds);
        $this->assertSame('202610001', $d->backdate['number'] ?? null);
    }

    public function testUnknownClientThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->draft(999999, '2026-10', null, null, null, null);
    }
}
