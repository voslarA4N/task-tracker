<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\MpdfInvoiceInv2Renderer;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\FakePdfRenderer;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class InvoiceIssuerTest extends PgTestCase
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

    public function testIssuesInvoiceWithAllStructuredColumns(): void
    {
        $s       = $this->issueSetup();
        $version = (int) $this->owner()->query('SELECT id FROM entity_versions WHERE entity_id = :e: AND valid_from <= :d: ORDER BY valid_from DESC LIMIT 1', ['e' => $s['entity'], 'd' => '2026-10-27'])->getRow()->id;

        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        $this->assertSame('202610001', $issued->number);
        $this->assertSame('1080.00', $issued->total);

        $row = $this->owner()->query('SELECT *, snapshot->>\'number\' AS snap_number, snapshot->\'totals\'->>\'total\' AS snap_total FROM invoices WHERE id = :id:', ['id' => $issued->id])->getRow();
        $this->assertSame('issued', $row->origin);
        $this->assertSame(1, (int) $row->line_count);
        $this->assertSame('1080.00', $row->total_entity_ccy);
        $this->assertContains($row->counts_toward_limit, [true, 't']);
        $this->assertSame('2026-10-31', $row->turnover_date);
        $this->assertSame('transfer', $row->payment_method);
        $this->assertNull($row->payment_note);
        $this->assertSame(2, (int) $row->snapshot_schema);
        $this->assertSame('inv-2', $row->template_version);
        $this->assertNull($row->fx_rate);
        $this->assertSame('sk', $row->doc_language);
        $this->assertSame('sk_non_vat', $row->vat_regime);
        $this->assertSame($version, (int) $row->entity_version_id);
        $this->assertSame('202610001', $row->snap_number);
        $this->assertSame($row->total, $row->snap_total);

        $line = $this->owner()->query('SELECT * FROM invoice_lines WHERE invoice_id = :id:', ['id' => $issued->id])->getRow();
        $this->assertSame('np', $line->vat_code);
        $this->assertSame('1080.00', $line->amount);
        $this->assertSame($s['parts'][0], (int) $line->source_allocation_id);

        $doc = $this->owner()->query('SELECT sha256 FROM invoice_documents WHERE invoice_id = :id:', ['id' => $issued->id])->getRow();
        $this->assertSame($issued->sha256, trim($doc->sha256));

        $this->assertSame($issued->id, (int) $this->owner()->query('SELECT invoice_id FROM billing_allocations WHERE id = :id:', ['id' => $s['parts'][0]])->getRow()->invoice_id);

        $audit = $this->owner()->query("SELECT after_data FROM audit_log WHERE entity_type = 'invoice' AND action = 'issue'")->getRow();
        $this->assertNotNull($audit);
        $this->assertArrayNotHasKey('snapshot', json_decode((string) $audit->after_data, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testNumbersFollowSeriesAndMonth(): void
    {
        $a = $this->issueSetup();
        // jeden subjekt a rad pre troch klientov
        $second = $this->createClient(['name' => 'Druhy']);
        $t      = $this->createTask($second);
        $p2     = $this->createAllocation($t, '5.00', '2026-10');
        $third  = $this->createClient(['name' => 'Treti']);
        $t3     = $this->createTask($third);
        $p3     = $this->createAllocation($t3, '5.00', '2026-10');

        $issuer = $this->issuer();
        $one    = $issuer->issue($this->issueCommand($a['client'], '2026-10', $a['entity'], $a['parts']));
        $two    = $issuer->issue($this->issueCommand($second, '2026-10', $a['entity'], [$p2]));
        $nov    = $issuer->issue($this->issueCommand($third, '2026-10', $a['entity'], [$p3], ['issueDate' => '2026-11-02']));

        $this->assertSame('202610001', $one->number);
        $this->assertSame('202610002', $two->number);
        $this->assertSame('202611001', $nov->number);
    }

    public function testLinePositionFollowsTaskCreationOrder(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['20.00', '2026-10']]]);
        $this->owner()->query("UPDATE tasks SET created_at = '2026-01-01 10:00:00+00' WHERE id = :id:", ['id' => $s['tasks'][1]]);

        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        $first = $this->owner()->query('SELECT source_task_id FROM invoice_lines WHERE invoice_id = :id: AND position = 1', ['id' => $issued->id])->getRow();
        $this->assertSame($s['tasks'][1], (int) $first->source_task_id);
    }

    public function testStoredDocumentCarriesTemplateVersionFromSnapshot(): void
    {
        $s      = $this->issueSetup();
        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        $doc = $this->owner()->query("SELECT template_version FROM invoice_documents WHERE invoice_id = :id: AND kind = 'invoice'", ['id' => $issued->id])->getRow();
        $this->assertSame('inv-2', $doc->template_version);
    }

    public function testRendererWithWrongTemplateVersionRollsBack(): void
    {
        $s        = $this->issueSetup();
        $counters = (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_counters')->getRow()->c;

        try {
            $this->issuer(new FakePdfRenderer('inv-1'))->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('inv-1', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM invoices')->getRow()->c);
        $this->assertSame($counters, (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_counters')->getRow()->c);
    }

    public function testRealPdfIsStoredWithMatchingHash(): void
    {
        $s      = $this->issueSetup();
        $issued = $this->issuer(new MpdfInvoiceInv2Renderer())->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        $content = $this->owner()->query("SELECT substring(content from 1 for 5) AS head FROM invoice_documents WHERE invoice_id = :id: AND kind = 'invoice'", ['id' => $issued->id])->getRow();
        $this->assertSame('%PDF-', pg_unescape_bytea((string) $content->head));

        $read = (new DocumentStore($this->rw()))->read($issued->id);
        $this->assertNotNull($read);
        $this->assertSame($issued->sha256, $read->sha256);
        $this->assertSame($issued->sha256, hash('sha256', $read->bytes));
    }
}
