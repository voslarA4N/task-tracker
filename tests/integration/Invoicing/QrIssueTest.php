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
final class QrIssueTest extends PgTestCase
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
     * @return array<string, mixed>
     */
    private function qrOf(int $invoiceId): array
    {
        $row = $this->owner()->query("SELECT snapshot->'payment'->'qr' AS qr FROM invoices WHERE id = :id:", ['id' => $invoiceId])->getRow();

        return ['qr' => $row->qr === null ? null : json_decode((string) $row->qr, true, 512, JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, mixed> $s
     */
    private function issue(array $s): int
    {
        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
    }

    private function setNip(int $entityId): void
    {
        $this->owner()->query("UPDATE entity_versions SET tax_id = '1234563218' WHERE entity_id = :e:", ['e' => $entityId]);
    }

    public function testSkClientGetsPayBySquare(): void
    {
        $s = $this->issueSetup();

        $this->assertSame('pay_by_square', $this->qrOf($this->issue($s))['qr']['standard']);
    }

    public function testCzClientGetsSpaydWithIbanAndAmount(): void
    {
        $s  = $this->issueSetup(['country' => 'CZ']);
        $qr = $this->qrOf($this->issue($s))['qr'];

        $this->assertSame('spayd', $qr['standard']);
        $this->assertStringContainsString('ACC:CZ6508000000192000145399', $qr['payload']);
        $this->assertStringContainsString('AM:1080.00', $qr['payload']);
        $this->assertStringContainsString('CC:CZK', $qr['payload']);
    }

    public function testPlClientGetsZbpWithGroszeAndNumber(): void
    {
        $s = $this->issueSetup(['country' => 'PL']);
        $this->setNip($s['entity']);
        $qr = $this->qrOf($this->issue($s))['qr'];

        $this->assertSame('zbp', $qr['standard']);
        $this->assertStringStartsWith('1234563218|PL|61109010140000071219812874|108000|', $qr['payload']);
        $this->assertStringContainsString('|202610001|', $qr['payload']);
    }

    public function testGermanClientHasNoQrAndIssues(): void
    {
        $s = $this->issueSetup(['client' => ['country' => 'DE']]);

        $this->assertNull($this->qrOf($this->issue($s))['qr']);
    }

    public function testPlClientWithoutNipFailsWithQrFailedAndNoInvoice(): void
    {
        $s = $this->issueSetup(['country' => 'PL']);

        try {
            $this->issue($s);
            $this->fail('Expected IssueRejected');
        } catch (IssueRejected $e) {
            $this->assertSame('qr_failed', $e->reason);
        }

        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM invoices')->getRow()->c);
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_counters')->getRow()->c);
    }
}
