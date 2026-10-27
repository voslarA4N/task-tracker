<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\IssueRejected;
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
final class BackdateTest extends PgTestCase
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

    private function invoiceCount(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM invoices')->getRow()->c;
    }

    public function testBackdatedIssueNeedsConfirmation(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.00', '2026-10']]]);

        $first = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]));
        $this->assertSame('202610001', $first->number);

        try {
            $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]], ['issueDate' => '2026-10-20']));
            $this->fail('Expected backdate_unconfirmed.');
        } catch (IssueRejected $e) {
            $this->assertSame('backdate_unconfirmed', $e->reason);
            $this->assertSame('202610001', $e->context['last_number']);
            $this->assertSame('2026-10-27', $e->context['last_date']);
        }

        $this->assertSame(1, $this->invoiceCount());

        $second = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]], ['issueDate' => '2026-10-20', 'confirmBackdate' => true]));
        $this->assertSame('202610002', $second->number);
    }

    public function testSameDateNeedsNoConfirmation(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.00', '2026-10']]]);

        $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]));
        $second = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]]));

        $this->assertSame('202610002', $second->number);
    }
}
