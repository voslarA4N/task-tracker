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
final class CurrencyGuardTest extends PgTestCase
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

    private function rejectedReason(callable $fn): IssueRejected
    {
        try {
            $fn();
        } catch (IssueRejected $e) {
            return $e;
        }

        $this->fail('Expected IssueRejected.');
    }

    private function written(): int
    {
        return (int) $this->owner()->query('SELECT (SELECT count(*) FROM invoices) + (SELECT count(*) FROM invoice_counters) AS c')->getRow()->c;
    }

    public function testEntityCurrencyDifferentFromClientCurrencyIsRejected(): void
    {
        $s = $this->issueSetup();
        // klient PLN pri subjekte EUR
        $this->owner()->query("UPDATE clients SET currency = 'PLN' WHERE id = :c:", ['c' => $s['client']]);
        $this->owner()->query("UPDATE billing_allocations SET currency = 'PLN' WHERE id = :p:", ['p' => $s['parts'][0]]);

        $e = $this->rejectedReason(fn () => $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'])));

        $this->assertSame('currency_mismatch', $e->reason);
        $this->assertSame(0, $this->written());
    }

    public function testMixedPartCurrenciesAreRejected(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.00', '2026-10']]]);
        $this->owner()->query("UPDATE billing_allocations SET currency = 'PLN' WHERE id = :p:", ['p' => $s['parts'][1]]);

        $e = $this->rejectedReason(fn () => $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'])));

        $this->assertSame('mixed_currency', $e->reason);
        $this->assertSame(0, $this->written());
    }

    public function testEndedEntityIsRejected(): void
    {
        $s = $this->issueSetup();
        $this->owner()->query("UPDATE entities SET active_to = '2026-10-15' WHERE id = :e:", ['e' => $s['entity']]);

        $e = $this->rejectedReason(fn () => $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'])));

        $this->assertSame('entity_inactive', $e->reason);
        $this->assertSame(0, $this->written());
    }
}
