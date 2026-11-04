<?php

declare(strict_types=1);

namespace Tests\Integration\Export;

use App\Libraries\Export\YearArchive;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Tests\Support\ReportFixtures;
use Tests\Support\SnapshotSample;
use Tests\Support\TurnoverFixtures;
use ZipArchive;

/**
 * NFR-09, D-10: rocny archiv = vsetky ulozene PDF roka (vsetky verzie) + CSV index so sha256.
 *
 * @internal
 */
#[Group('integration')]
final class YearArchiveTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use CreditNoteFixtures;
    use IssueFixtures;
    use TurnoverFixtures;
    use ReportFixtures;
    use StreamFilterTrait;

    private int $entity;
    private int $client;

    /**
     * @var array{a: int, b: int, cn: int, c: int}
     */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        $this->removeArchive();
        Services::injectMock('clock', new FrozenClock('2027-01-02T10:00:00+01:00'));
        $this->entity = $this->createEntity('SK', ['active_from' => '2024-01-01']);
        $this->client = $this->createClient(['name' => '=1+1']);

        $snapshot = json_encode(SnapshotSample::issued(['client' => ['id' => 1, 'name' => '=1+1']]), JSON_THROW_ON_ERROR);

        $a = $this->reportInvoice($this->entity, $this->client, '2026-10-05', '1000.00', ['with_report' => true, 'snapshot' => $snapshot]);
        $b = $this->reportInvoice($this->entity, $this->client, '2026-10-12', '200.00', ['snapshot' => $snapshot]);
        $this->addDocumentVersion($b, 2, '%PDF-1.4 invoice B regenerated');
        $cn = $this->insertRawCreditNote($a, ['issue_date' => '2026-11-20', 'snapshot' => $snapshot]);
        $c  = $this->reportInvoice($this->entity, $this->client, '2025-09-10', '400.00', ['snapshot' => $snapshot]);

        $task  = $this->createTask($this->client, ['rate' => '100.00', 'hours' => '1.00']);
        $alloc = $this->createAllocation($task, '1.00', '2026-10', ['rate' => '100.00']);
        $this->insertExternalInvoice($this->client, '2026-10', [$alloc]);

        $this->ids = ['a' => $a, 'b' => $b, 'cn' => $cn, 'c' => $c];
    }

    protected function tearDown(): void
    {
        $this->removeArchive();
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function removeArchive(): void
    {
        $dir = WRITEPATH . 'archive';

        if (! is_dir($dir)) {
            return;
        }

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }

        rmdir($dir);
    }

    private function addDocumentVersion(int $invoiceId, int $version, string $bytes): void
    {
        $this->owner()->query(
            "INSERT INTO invoice_documents (invoice_id, kind, version, content, byte_size, sha256, template_version, renderer, created_at)
             VALUES (:i:, 'invoice', :v:, decode(:hex:, 'hex'), :size:, :sha:, 'test-2', 'test', now())",
            ['i' => $invoiceId, 'v' => $version, 'hex' => bin2hex($bytes), 'size' => strlen($bytes), 'sha' => hash('sha256', $bytes)],
        );
    }

    /**
     * @return array{names: list<string>, csv: string, zip: ZipArchive}
     */
    private function open(string $path): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        return ['names' => $names, 'csv' => (string) $zip->getFromName('zoznam-dokladov.csv'), 'zip' => $zip];
    }

    /**
     * @return list<list<string|null>>
     */
    private function csvRows(string $csv): array
    {
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $rows  = [];

        foreach ($lines as $line) {
            $rows[] = str_getcsv($line, ';', '"', '');
        }

        return $rows;
    }

    public function testBuildContainsAllVersionsOfIssuedDocumentsOfTheYear(): void
    {
        $result = (new YearArchive($this->rw()))->build(2026);

        $this->assertSame(WRITEPATH . 'archive/2026/work-tracker-archiv-2026.zip', $result->path);
        $this->assertFileExists($result->path);
        $this->assertSame(0, fileperms($result->path) & 0007, 'ZIP must not be accessible by others.');
        $this->assertSame(3, $result->documents);
        $this->assertSame(5, $result->pdfs);
        $this->assertSame(filesize($result->path), $result->bytes);
        $this->assertSame(hash_file('sha256', $result->path), $result->sha256);

        $opened = $this->open($result->path);

        try {
            $pdfs = array_values(array_filter($opened['names'], static fn (string $n): bool => str_starts_with($n, 'doklady/')));
            $this->assertCount(5, $pdfs);
            $this->assertContains('zoznam-dokladov.csv', $opened['names']);
            $this->assertContains('README.txt', $opened['names']);
            $this->assertSame($pdfs, array_values(array_unique($pdfs)));
        } finally {
            $opened['zip']->close();
        }
    }

    public function testEveryPdfMatchesCsvAndDatabaseSha256(): void
    {
        $result = (new YearArchive($this->rw()))->build(2026);
        $opened = $this->open($result->path);

        try {
            $rows = $this->csvRows($opened['csv']);
            $head = (array) array_shift($rows);
            $col  = array_flip(array_map(static fn ($h): string => ltrim((string) $h, "\xEF\xBB\xBF"), $head));
            $this->assertCount(5, $rows);

            foreach ($rows as $r) {
                $content = $opened['zip']->getFromName('doklady/' . $r[$col['súbor']]);
                $this->assertIsString($content);
                $this->assertSame($r[$col['sha256']], hash('sha256', $content));

                $db = $this->rw()->query('SELECT count(*) AS c FROM invoice_documents WHERE sha256 = :s:', ['s' => $r[$col['sha256']]])->getRow();
                $this->assertGreaterThanOrEqual(1, (int) $db->c);
            }
        } finally {
            $opened['zip']->close();
        }

        $this->assertSame($result->sha256 . '  work-tracker-archiv-2026.zip' . "\n", (string) file_get_contents($result->path . '.sha256'));
    }

    public function testCsvFormatAndExclusions(): void
    {
        $result = (new YearArchive($this->rw()))->build(2026);
        $opened = $this->open($result->path);
        $csv    = $opened['csv'];
        $opened['zip']->close();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('číslo;typ;dátum;subjekt;klient;suma;mena;sha256;súbor', (string) strtok($csv, "\n"));
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('dobropis', $csv);
        $this->assertMatchesRegularExpression('/;-\d+,\d{2};/', $csv);

        $c = (string) $this->owner()->query('SELECT number FROM invoices WHERE id = :i:', ['i' => $this->ids['c']])->getRow()->number;
        $this->assertStringNotContainsString($c, $csv);
    }

    public function testCsvUsesSnapshotNotLiveMasterData(): void
    {
        $this->owner()->query("UPDATE clients SET name = 'Premenovany klient'");
        $result = (new YearArchive($this->rw()))->build(2026);
        $opened = $this->open($result->path);
        $csv    = $opened['csv'];
        $opened['zip']->close();

        $this->assertStringNotContainsString('Premenovany klient', $csv);
        $this->assertStringContainsString("'=1+1", $csv);
    }

    public function testEmptyYearProducesEmptyIndex(): void
    {
        $result = (new YearArchive($this->rw()))->build(2024);

        $this->assertSame(0, $result->documents);
        $this->assertSame(0, $result->pdfs);
        $opened = $this->open($result->path);

        try {
            $this->assertCount(1, $this->csvRows($opened['csv']));
        } finally {
            $opened['zip']->close();
        }
    }

    public function testRebuildOverwritesExistingArchive(): void
    {
        $first  = (new YearArchive($this->rw()))->build(2026);
        $second = (new YearArchive($this->rw()))->build(2026);

        $this->assertSame($first->path, $second->path);
        $this->assertSame([], glob(WRITEPATH . 'archive/2026/*.partial') ?: []);
        $this->assertSame([], glob(WRITEPATH . 'archive/tmp/*') ?: []);
    }

    private function run2(string $args): string
    {
        $this->resetStreamFilterBuffer();
        command('archive:year' . ($args === '' ? '' : ' ' . $args));

        return $this->getStreamFilterBuffer();
    }

    public function testCommandBuildsArchiveAndPrintsPath(): void
    {
        $out = $this->run2('2026');

        $this->assertStringContainsString('Archív roka 2026: 3 dokladov, 5 PDF', $out);
        $this->assertStringContainsString('PATH=' . WRITEPATH . 'archive/2026/work-tracker-archiv-2026.zip', $out);
        $this->assertFileExists(WRITEPATH . 'archive/2026/work-tracker-archiv-2026.zip');
    }

    public function testCommandRejectsInvalidYear(): void
    {
        $out = $this->run2('20x6');

        $this->assertStringContainsString('Neplatný', $out);
        $this->assertDirectoryDoesNotExist(WRITEPATH . 'archive');
    }

    public function testCommandDefaultsToPreviousYear(): void
    {
        $out = $this->run2('');

        $this->assertStringContainsString('Archív roka 2026:', $out);
        $this->assertFileExists(WRITEPATH . 'archive/2026/work-tracker-archiv-2026.zip');
    }
}
