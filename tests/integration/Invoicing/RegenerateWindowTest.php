<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\DocumentSettingsService;
use App\Libraries\Invoicing\RegenerateRejected;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Tests\Support\RegenerateFixtures;

/**
 * Lehota pregenerovania PDF faktury (D-06, A2, A3).
 *
 * @internal
 */
#[Group('integration')]
final class RegenerateWindowTest extends PgTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;
    use RegenerateFixtures;

    private const ISSUED_AT = '2026-11-01T10:00:00+01:00';

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock(self::ISSUED_AT));
        $this->resetMasterTables();
        $this->setWindow(72);
    }

    protected function tearDown(): void
    {
        $this->owner()->query('UPDATE document_settings SET regen_invoice_hours = 72 WHERE id = 1');
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function setWindow(int $hours): void
    {
        $settings = new DocumentSettingsService($this->rw());
        $settings->update((string) $hours, $settings->current()['revision']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function issued(array $overrides = []): int
    {
        $s = $this->issueSetup();

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], $overrides + ['issueDate' => '2026-11-01', 'deliveryDate' => '2026-10-31', 'dueDate' => '2026-11-15']))->id;
    }

    private function at(string $time): void
    {
        Services::injectMock('clock', new FrozenClock($time));
    }

    private function versions(int $id, string $kind): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_documents WHERE invoice_id = :i: AND kind = :k:', ['i' => $id, 'k' => $kind])->getRow()->c;
    }

    private function assertExpired(int $id, string $kind = 'invoice'): void
    {
        try {
            $this->regenerator()->regenerate($id, $kind, 1);
            $this->fail('Expected window_expired');
        } catch (RegenerateRejected $e) {
            $this->assertSame('window_expired', $e->reason);
        }
    }

    public function testDefaultWindowBoundary(): void
    {
        $id = $this->issued();

        $this->at('2026-11-04T10:00:01+01:00');
        $this->assertExpired($id);
        $this->assertSame(1, $this->versions($id, 'invoice'));

        $this->at('2026-11-04T10:00:00+01:00');
        $this->assertSame(2, $this->regenerator()->regenerate($id, 'invoice', 1)->version);
    }

    public function testZeroWindowBlocksInvoiceButNotReport(): void
    {
        $id = $this->issued();
        $this->setWindow(0);

        $this->assertExpired($id);
        $this->assertSame(2, $this->regenerator()->regenerate($id, 'report', 1)->version);
    }

    public function testOneHourWindow(): void
    {
        $id = $this->issued();
        $this->setWindow(1);

        $this->at('2026-11-01T10:59:00+01:00');
        $this->assertSame(2, $this->regenerator()->regenerate($id, 'invoice', 1)->version);

        $this->at('2026-11-01T11:01:00+01:00');
        $this->assertExpired($id);
        $this->assertSame(2, $this->versions($id, 'invoice'));
    }

    public function testReportHasNoWindow(): void
    {
        $id = $this->issued();

        $this->at('2027-12-06T10:00:00+01:00');
        $this->assertSame(2, $this->regenerator()->regenerate($id, 'report', 1)->version);
    }

    public function testWindowCountsFromCreatedAtNotIssueDate(): void
    {
        $id = $this->issued(['issueDate' => '2026-10-22', 'confirmBackdate' => true]);

        $this->at('2026-11-01T11:00:00+01:00');
        $this->assertSame(2, $this->regenerator()->regenerate($id, 'invoice', 1)->version);
    }

    public function testCancelledInvoiceCanBeRegenerated(): void
    {
        $id = $this->issued();
        $this->issueCreditNote($id, ['issueDate' => '2026-11-01']);

        $this->at('2026-11-01T11:00:00+01:00');
        $this->assertSame(2, $this->regenerator()->regenerate($id, 'report', 1)->version);
        $this->assertSame(2, $this->regenerator()->regenerate($id, 'invoice', 1)->version);
    }
}
