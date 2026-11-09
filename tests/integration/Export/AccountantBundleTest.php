<?php

declare(strict_types=1);

namespace Tests\Integration\Export;

use App\Libraries\Export\AccountantBundle;
use App\Libraries\Export\BundleRejected;
use App\Libraries\Export\DocumentListExport;
use App\Libraries\Invoicing\DocumentFilename;
use App\Libraries\Invoicing\DocumentIntegrityError;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Money;
use App\Libraries\Reporting\BundleSelector;
use App\Libraries\Reporting\ReportCalculator;
use App\Libraries\Reporting\ReportPeriod;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Tests\Support\ReportFixtures;
use Tests\Support\TurnoverFixtures;
use Throwable;
use ZipArchive;

/**
 * RPT-03, D-09 az D-12: balik = ulozene PDF + CSV zoznam, rovnaky vyber a sucet ako prehlad.
 *
 * @internal
 */
#[Group('integration')]
final class AccountantBundleTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use CreditNoteFixtures;
    use IssueFixtures;
    use TurnoverFixtures;
    use ReportFixtures;

    private int $entity;
    private int $client;

    /**
     * @var array{a: int, b: int, cn: int}
     */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        $this->entity = $this->createEntity('SK', ['active_from' => '2025-01-01']);
        $this->client = $this->createClient(['name' => '=1+1']);
        $other        = $this->createClient(['name' => 'Iny', 'identifier_value' => '22222222']);

        $a  = $this->reportInvoice($this->entity, $this->client, '2026-10-05', '1000.00');
        $b  = $this->reportInvoice($this->entity, $this->client, '2026-10-12', '200.00');
        $cn = $this->turnoverCreditNote($b, '2026-10-20');
        $this->reportInvoice($this->entity, $this->client, '2026-09-10', '400.00');
        $otherEntity = $this->createEntity('SK', ['active_from' => '2025-01-01', 'name_internal' => 'Druhy']);
        $this->reportInvoice($otherEntity, $other, '2026-10-06', '999.00');
        $task  = $this->createTask($this->client, ['rate' => '100.00', 'hours' => '1.00']);
        $alloc = $this->createAllocation($task, '1.00', '2026-10', ['rate' => '100.00']);
        $this->insertExternalInvoice($this->client, '2026-10', [$alloc]);

        $this->ids = ['a' => $a, 'b' => $b, 'cn' => $cn];
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::range('2026-10', '2026-10');
    }

    /**
     * @return array{zip: ZipArchive, path: string}
     */
    private function open(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'bz');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        return ['zip' => $zip, 'path' => $path];
    }

    private function dropDocument(int $invoiceId): void
    {
        $db = $this->owner();
        $db->query('BEGIN');

        try {
            $db->query('ALTER TABLE invoice_documents DISABLE TRIGGER invoice_documents_immutable');
            $db->query("DELETE FROM invoice_documents WHERE invoice_id = :i: AND kind = 'invoice'", ['i' => $invoiceId]);
            $db->query('ALTER TABLE invoice_documents ENABLE TRIGGER invoice_documents_immutable');
            $db->query('COMMIT');
        } catch (Throwable $e) {
            $db->query('ROLLBACK');

            throw $e;
        }
    }

    public function testSelectorReturnsOnlyIssuedInPeriodOrdered(): void
    {
        $docs = (new BundleSelector($this->rw()))->documents($this->entity, $this->period());

        $this->assertCount(3, $docs);
        $this->assertSame([$this->ids['a'], $this->ids['cn'], $this->ids['b']], array_column($docs, 'id'));
        $this->assertSame(['invoice', 'credit_note', 'invoice'], array_column($docs, 'type'));
        $this->assertSame('cancelled', $docs[2]['status']);
    }

    public function testBuildContainsStoredPdfsAndCsv(): void
    {
        $bytes                           = (new AccountantBundle($this->rw()))->build($this->entity, $this->period());
        ['zip' => $zip, 'path' => $path] = $this->open($bytes);

        try {
            $docs  = (new BundleSelector($this->rw()))->documents($this->entity, $this->period());
            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }

            $expected = ['zoznam-dokladov.csv'];

            foreach ($docs as $d) {
                $kind       = $d['type'] === 'credit_note' ? 'credit_note' : 'invoice';
                $expected[] = 'doklady/' . DocumentFilename::for($kind, $d['doc_language'], (string) $d['number']);

                $stored = $this->rw()->query("SELECT sha256 FROM invoice_documents WHERE invoice_id = :i: AND kind = 'invoice'", ['i' => $d['id']])->getRow();
                $inZip  = $zip->getFromName('doklady/' . DocumentListExport::pdfName($d));
                $this->assertSame(trim((string) $stored->sha256), hash('sha256', (string) $inZip));
            }

            sort($expected);
            sort($names);
            $this->assertSame($expected, $names);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function testCsvMatchesReport(): void
    {
        $bytes                           = (new AccountantBundle($this->rw()))->build($this->entity, $this->period());
        ['zip' => $zip, 'path' => $path] = $this->open($bytes);

        try {
            $csv = (string) $zip->getFromName('zoznam-dokladov.csv');
        } finally {
            $zip->close();
            unlink($path);
        }

        $this->assertStringStartsWith("\xEF\xBB\xBFČíslo;Typ;Klient;", $csv);
        $this->assertStringContainsString(';Súbor', strtok($csv, "\n"));
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('Stornovaná', $csv);

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(4, $lines);

        $sum = Money::zero();

        foreach (array_slice($lines, 1) as $line) {
            $cols = str_getcsv($line, ';', '"', '');
            $sum  = $sum->plus(Money::of(str_replace(',', '.', $cols[9])));
        }

        $report = (new ReportCalculator($this->rw()))->forPeriod($this->entity, $this->period());
        $this->assertSame($report->invoicedTotal, $sum->toDecimalString());
    }

    public function testMissingPdfRejectsWithoutLeftovers(): void
    {
        $id = $this->reportInvoice($this->entity, $this->client, '2026-10-15', '50.00', ['number' => 'NOPDF1']);
        $this->dropDocument($id);
        $before = glob(WRITEPATH . 'exports/zip*') ?: [];

        try {
            (new AccountantBundle($this->rw()))->build($this->entity, $this->period());
            $this->fail('Expected BundleRejected');
        } catch (BundleRejected $e) {
            $this->assertSame('missing_pdf', $e->reason);
            $this->assertSame(['NOPDF1'], $e->numbers);
        }

        $this->assertSame($before, glob(WRITEPATH . 'exports/zip*') ?: []);
    }

    public function testIntegrityFailureLeavesNoFile(): void
    {
        // Corrupt the LAST document (a pending entry already sits in the archive when it fails).
        // The DB CHECK normally makes a sha256 mismatch impossible, so lift it for the duration of the test.
        $id    = $this->reportInvoice($this->entity, $this->client, '2026-10-28', '50.00', ['number' => 'BADSHA1']);
        $owner = $this->owner();
        $guard = "ALTER TABLE invoice_documents %s TRIGGER invoice_documents_immutable";
        $owner->query(sprintf($guard, 'DISABLE'));
        $owner->query('ALTER TABLE invoice_documents DROP CONSTRAINT invoice_documents_check1');
        $good = (string) $owner->query("SELECT sha256 FROM invoice_documents WHERE invoice_id = :i: AND kind = 'invoice'", ['i' => $id])->getRow()->sha256;
        $owner->query("UPDATE invoice_documents SET sha256 = :s: WHERE invoice_id = :i: AND kind = 'invoice'", ['s' => str_repeat('a', 64), 'i' => $id]);
        $owner->query(sprintf($guard, 'ENABLE'));

        $before = glob(WRITEPATH . 'exports/zip*') ?: [];

        try {
            (new AccountantBundle($this->rw()))->build($this->entity, $this->period());
            $this->fail('Expected DocumentIntegrityError');
        } catch (DocumentIntegrityError) {
            $this->addToAssertionCount(1);
        } finally {
            $owner->query(sprintf($guard, 'DISABLE'));
            $owner->query("UPDATE invoice_documents SET sha256 = :s: WHERE invoice_id = :i: AND kind = 'invoice'", ['s' => $good, 'i' => $id]);
            $owner->query("ALTER TABLE invoice_documents ADD CONSTRAINT invoice_documents_check1 CHECK (sha256 = encode(sha256(content), 'hex'))");
            $owner->query(sprintf($guard, 'ENABLE'));
        }

        $this->assertSame($before, glob(WRITEPATH . 'exports/zip*') ?: []);
    }

    public function testEmptyPeriodRejected(): void
    {
        try {
            (new AccountantBundle($this->rw()))->build($this->entity, ReportPeriod::range('2025-01', '2025-01'));
            $this->fail('Expected BundleRejected');
        } catch (BundleRejected $e) {
            $this->assertSame('empty', $e->reason);
        }
    }

    public function testExternalRowHasEmptyEntityAmount(): void
    {
        $table = DocumentListExport::table([[
            'id'         => 1, 'origin' => 'external', 'type' => 'invoice', 'number' => null, 'external_ref' => 'EXT-1', 'client_name' => 'K',
            'issue_date' => '2026-10-01', 'turnover_date' => null, 'due_date' => null, 'currency' => 'EUR', 'total' => '10.00',
            'fx_rate'    => null, 'total_entity_ccy' => null, 'status' => 'external', 'last_paid_on' => null, 'doc_language' => 'sk', 'byte_size' => null,
        ]], false);

        $row = $table->rows[0];
        $this->assertSame('EXT-1', $row[0]->value);
        $this->assertSame('Externá faktúra', $row[1]->value);
        $this->assertSame('', $row[9]->value);
        $this->assertSame('', $row[8]->value);
    }

    private function addDocumentVersion(int $invoiceId, int $version, string $bytes): void
    {
        $this->owner()->query(
            "INSERT INTO invoice_documents (invoice_id, kind, version, content, byte_size, sha256, template_version, renderer, created_at)
             VALUES (:i:, 'invoice', :v:, decode(:hex:, 'hex'), :size:, :sha:, 'test-2', 'test', now())",
            ['i' => $invoiceId, 'v' => $version, 'hex' => bin2hex($bytes), 'size' => strlen($bytes), 'sha' => hash('sha256', $bytes)],
        );
    }

    public function testRegeneratedVersionAppearsOnceWithLatestBytes(): void
    {
        $bytes = '%PDF-1.4 regenerated invoice A, clearly different size';
        $this->addDocumentVersion($this->ids['a'], 2, $bytes);

        $docs = (new BundleSelector($this->rw()))->documents($this->entity, $this->period());
        $this->assertCount(3, $docs);
        $this->assertSame([$this->ids['a'], $this->ids['cn'], $this->ids['b']], array_column($docs, 'id'));
        $this->assertSame(strlen($bytes), $docs[0]['byte_size']);

        $zipBytes                        = (new AccountantBundle($this->rw()))->build($this->entity, $this->period());
        ['zip' => $zip, 'path' => $path] = $this->open($zipBytes);

        try {
            $this->assertSame(4, $zip->numFiles);
            $this->assertSame($bytes, $zip->getFromName('doklady/' . DocumentListExport::pdfName($docs[0])));
        } finally {
            $zip->close();
            unlink($path);
        }
    }
}
