<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Invoicing\PdfRenderer;
use App\Libraries\Invoicing\RenderedPdf;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\FakePdfRenderer;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * D-05: vlastna faktura schema >= 2 musi mat dokument report (deferred trigger), D-20: rollback pri zlyhani.
 *
 * @internal
 */
#[Group('integration')]
final class ReportDocumentTest extends PgTestCase
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

    public function testIssueStoresInvoiceAndReportDocuments(): void
    {
        $s      = $this->issueSetup();
        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        $rows = $this->owner()->query('SELECT kind, template_version, sha256, content FROM invoice_documents WHERE invoice_id = :i: ORDER BY kind', ['i' => $issued->id])->getResultArray();
        $this->assertSame(['invoice', 'report'], array_column($rows, 'kind'));
        $this->assertSame(['inv-2', 'rep-1'], array_column($rows, 'template_version'));

        foreach ($rows as $row) {
            $this->assertSame(hash('sha256', pg_unescape_bytea((string) $row['content'])), trim((string) $row['sha256']));
        }

        $audit = (string) $this->owner()->query("SELECT after_data FROM audit_log WHERE entity_type = 'invoice' AND action = 'issue'")->getRow()->after_data;
        $this->assertStringContainsString('report_sha256', $audit);
    }

    public function testSchema2WithoutReportCannotCommit(): void
    {
        $this->assertIncomplete(2, false);
    }

    public function testSchema2WithReportCommits(): void
    {
        $id = $this->rawInvoice(2, true);
        $this->assertGreaterThan(0, $id);
    }

    public function testSchema1WithoutReportCommits(): void
    {
        $id = $this->rawInvoice(1, false);
        $this->assertGreaterThan(0, $id);
    }

    public function testReportRenderFailureRejectsAndLeavesNothing(): void
    {
        $s = $this->issueSetup();

        try {
            $this->issuer(null, new class () implements PdfRenderer {
                public function render(array $snapshot): RenderedPdf
                {
                    throw new RuntimeException('boom');
                }
            })->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
            $this->fail('Expected IssueRejected');
        } catch (IssueRejected $e) {
            $this->assertSame('report_failed', $e->reason);
        }

        foreach (['invoices', 'invoice_documents', 'invoice_counters'] as $t) {
            $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM ' . $t)->getRow()->c, $t);
        }

        $this->assertNull($this->owner()->query('SELECT invoice_id FROM billing_allocations WHERE id = :id:', ['id' => $s['parts'][0]])->getRow()->invoice_id);
        $this->assertSame('202610001', $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->number);
    }

    public function testReportTemplateVersionMismatchRollsBack(): void
    {
        $s = $this->issueSetup();

        try {
            $this->issuer(null, new FakePdfRenderer('rep-9'))->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Report renderer returned', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM invoices')->getRow()->c);
    }

    public function testReportDocumentIsImmutable(): void
    {
        $s      = $this->issueSetup();
        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        foreach (['rw', 'owner'] as $conn) {
            foreach (["UPDATE invoice_documents SET renderer = 'x' WHERE kind = 'report'", "DELETE FROM invoice_documents WHERE kind = 'report'"] as $sql) {
                try {
                    $this->{$conn}()->query($sql);
                    $this->fail('Expected failure: ' . $conn . ' ' . $sql);
                } catch (DatabaseException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM invoice_documents WHERE kind = 'report' AND invoice_id = :i:", ['i' => $issued->id])->getRow()->c);
    }

    private function rawInvoice(int $schema, bool $withReport): int
    {
        $entity = $this->createEntity('SK');
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '8.00', '2026-10');

        return $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc], '%PDF-1.4 test', ['snapshot_schema' => $schema, 'with_report' => $withReport]);
    }

    private function assertIncomplete(int $schema, bool $withReport): void
    {
        try {
            $this->rawInvoice($schema, $withReport);
            $this->fail('Expected incomplete invoice failure');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('is incomplete', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM invoices')->getRow()->c);
    }
}
