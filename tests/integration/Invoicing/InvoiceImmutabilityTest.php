<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class InvoiceImmutabilityTest extends PgTestCase
{
    use InvoiceFixtures;
    use MasterFixtures;

    private int $series;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();

        $entity = $this->createEntity('SK');
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '8.00', '2026-10');
        $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc]);
        $this->series = (int) $this->owner()->query("SELECT id FROM invoice_series WHERE entity_id = {$entity} AND kind = 'invoice'")->getRow()->id;
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function assertDbError(string $regex, callable $fn): void
    {
        try {
            $fn();
        } catch (DatabaseException $e) {
            $this->assertMatchesRegularExpression($regex, $e->getMessage());

            return;
        }

        $this->fail('Expected DatabaseException matching ' . $regex);
    }

    public function testAppRwCannotModifyInvoiceTables(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE invoices SET total = 1'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM invoices'));
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE invoice_lines SET title = 'x'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM invoice_documents'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('TRUNCATE invoices'));
    }

    public function testOwnerBlockedByTrigger(): void
    {
        $this->assertDbError('/is immutable/', fn () => $this->owner()->query('UPDATE invoices SET total = 1'));
        $this->assertDbError('/is immutable/', fn () => $this->owner()->query('DELETE FROM invoice_lines'));
        $this->assertDbError('/is immutable/', fn () => $this->owner()->query('UPDATE invoice_documents SET renderer = \'x\''));
    }

    public function testOwnerTruncateBlockedByTrigger(): void
    {
        // WR-07: riadkove triggery TRUNCATE neodchytia, statement trigger ano (aj CASCADE z inej tabulky).
        $this->rw()->query("INSERT INTO invoice_counters (series_id, period_key, last_value) VALUES ({$this->series}, '202610', 1)");

        $this->assertDbError('/invoices is immutable/', fn () => $this->owner()->query('TRUNCATE invoices CASCADE'));
        $this->assertDbError('/invoice_lines is immutable/', fn () => $this->owner()->query('TRUNCATE invoice_lines'));
        $this->assertDbError('/invoice_documents is immutable/', fn () => $this->owner()->query('TRUNCATE invoice_documents'));
        $this->assertDbError('/invoice_counters is immutable/', fn () => $this->owner()->query('TRUNCATE invoice_counters'));
        $this->assertDbError('/is immutable/', fn () => $this->owner()->query('TRUNCATE clients CASCADE'));
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM invoices')->getRow()->c);
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_counters')->getRow()->c);
    }

    public function testCounterRules(): void
    {
        $this->rw()->query("INSERT INTO invoice_counters (series_id, period_key, last_value) VALUES ({$this->series}, '202610', 1)");
        $this->rw()->query('UPDATE invoice_counters SET last_value = last_value + 1');
        $this->assertSame(2, (int) $this->owner()->query('SELECT last_value FROM invoice_counters')->getRow()->last_value);

        $this->assertDbError('/may only increase by one/', fn () => $this->rw()->query('UPDATE invoice_counters SET last_value = last_value - 1'));
        $this->assertDbError('/may only increase by one/', fn () => $this->rw()->query('UPDATE invoice_counters SET last_value = last_value + 2'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM invoice_counters'));
        $this->assertDbError('/may not be deleted/', fn () => $this->owner()->query('DELETE FROM invoice_counters'));
    }

    public function testCounterInsertMustStartAtFirstValue(): void
    {
        // WR-06: rad uz ma fakturu, nove obdobie smie zacat iba 1 (ani owner nevlozi medzeru).
        $this->assertDbError('/must start at 1/', fn () => $this->rw()->query("INSERT INTO invoice_counters (series_id, period_key, last_value) VALUES ({$this->series}, '202611', 500)"));
        $this->assertDbError('/must start at 1/', fn () => $this->owner()->query("INSERT INTO invoice_counters (series_id, period_key, last_value) VALUES ({$this->series}, '202611', 2)"));

        // Rad bez faktur zacina initial_number (D-12), nie 1 ani inou hodnotou.
        $fresh = $this->createEntity('PL');
        $other = (int) $this->owner()->query("SELECT id FROM invoice_series WHERE entity_id = {$fresh} AND kind = 'invoice'")->getRow()->id;
        $this->owner()->query("UPDATE invoice_series SET initial_number = 7 WHERE id = {$other}");

        $this->assertDbError('/must start at 7/', fn () => $this->rw()->query("INSERT INTO invoice_counters (series_id, period_key, last_value) VALUES ({$other}, '202610', 1)"));
        $this->rw()->query("INSERT INTO invoice_counters (series_id, period_key, last_value) VALUES ({$other}, '202610', 7)");
        $this->assertSame(7, (int) $this->owner()->query("SELECT last_value FROM invoice_counters WHERE series_id = {$other}")->getRow()->last_value);
    }
}
