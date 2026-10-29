<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\InvoiceQueries;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class InvoiceQueriesTest extends PgTestCase
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

    /**
     * @return array{first: int, second: int, external: int}
     */
    private function seed(): array
    {
        $s = $this->issueSetup(['parts' => [['40.00', '2026-10'], ['8.00', '2026-10']]]);

        $first  = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]], ['issueDate' => '2026-10-27']));
        $second = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]], ['issueDate' => '2026-10-28']));

        $task     = $this->createTask($s['client']);
        $external = $this->lockAllocation($this->createAllocation($task, '16.00', '2026-09'));

        return ['first' => $first->id, 'second' => $second->id, 'external' => $external];
    }

    public function testStatusFilterAndOverdueFlag(): void
    {
        $ids = $this->seed();
        $db  = $this->owner();
        $db->query('INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, :d:, :a:, now())', ['i' => $ids['first'], 'd' => '2026-10-28', 'a' => '10.00']);
        $q = new InvoiceQueries($this->rw());

        $partial = $q->list(100, 'partially_paid', '2026-10-27');
        $this->assertSame([$ids['first']], array_column($partial['rows'], 'id'));
        $this->assertSame('partially_paid', $partial['rows'][0]['status']);
        $this->assertFalse($partial['rows'][0]['overdue']);

        $overdue = $q->list(100, 'overdue', '2026-12-01');
        $this->assertEqualsCanonicalizing([$ids['first'], $ids['second']], array_column($overdue['rows'], 'id'));
        $this->assertTrue($overdue['rows'][0]['overdue']);
        $this->assertGreaterThan(0, $overdue['rows'][0]['days_overdue']);
        $this->assertArrayHasKey('balance', $overdue['rows'][0]);

        $this->assertCount(3, $q->list(100, 'nezmysel', '2026-10-27')['rows']);
        $this->assertSame([], $q->list(100, 'paid', '2026-10-27')['rows']);
        $this->assertSame([], $q->list(100, 'overdue', '2026-10-27')['rows']);
    }

    public function testListOrdersNewestFirstAndHidesBinaryColumns(): void
    {
        $ids    = $this->seed();
        $result = (new InvoiceQueries($this->rw()))->list();

        $this->assertCount(3, $result['rows']);
        $this->assertFalse($result['truncated']);
        $this->assertSame($ids['second'], (int) $result['rows'][0]['id']);
        $this->assertSame($ids['first'], (int) $result['rows'][1]['id']);
        $this->assertSame('external', $result['rows'][2]['origin']);

        foreach ($result['rows'] as $row) {
            $this->assertArrayNotHasKey('snapshot', $row);
            $this->assertArrayNotHasKey('content', $row);
        }
    }

    public function testListTruncates(): void
    {
        $this->seed();
        $result = (new InvoiceQueries($this->rw()))->list(1);

        $this->assertCount(1, $result['rows']);
        $this->assertTrue($result['truncated']);
    }

    public function testDetailOfIssuedInvoice(): void
    {
        $ids = $this->seed();
        $d   = (new InvoiceQueries($this->rw()))->detail($ids['first']);

        $this->assertNotNull($d);
        $this->assertSame('202610001', $d['snapshot']['number']);
        $this->assertSame('issued', $d['origin']);
        $this->assertCount(1, $d['lines']);
        $this->assertTrue($d['lines'][0]['task_exists']);
        $this->assertSame(64, strlen((string) $d['document']['sha256']));
        $this->assertArrayNotHasKey('content', $d['document']);
    }

    public function testDetailOfExternalInvoice(): void
    {
        $ids = $this->seed();
        $d   = (new InvoiceQueries($this->rw()))->detail($ids['external']);

        $this->assertNotNull($d);
        $this->assertSame('external', $d['origin']);
        $this->assertNull($d['document']);
    }

    public function testDetailMissingReturnsNull(): void
    {
        $this->assertNull((new InvoiceQueries($this->rw()))->detail(999999));
    }
}
