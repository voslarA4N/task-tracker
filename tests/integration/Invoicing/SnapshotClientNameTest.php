<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Export\AccountantBundle;
use App\Libraries\Export\DocumentListExport;
use App\Libraries\Invoicing\InvoiceQueries;
use App\Libraries\Master\ClientService;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Receivables\InvoiceStatusQueries;
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
use ZipArchive;

/**
 * D-05: po premenovani klienta ukazuju vsetky citacie cesty knižnice povodny nazov zo snapshotu vystaveneho dokladu.
 * Hlavicky platby a dodania (controller-private cesty) pokryva tests/feature/Invoicing/SnapshotClientNameHeadersTest.php.
 *
 * @internal
 */
#[Group('integration')]
final class SnapshotClientNameTest extends PgTestCase
{
    use MasterFixtures;
    use IssueFixtures;
    use CreditNoteFixtures;
    use InvoiceFixtures;

    private const ORIGINAL = 'Povodny s.r.o.';
    private const RENAMED  = 'Premenovany s.r.o.';

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

    public function testRenamedClientKeepsOriginalNameOnEveryReadPath(): void
    {
        $s = $this->issueSetup([
            'client' => ['name' => self::ORIGINAL],
            'parts'  => [['10.00', '2026-10'], ['5.00', '2026-10']],
        ]);

        $first  = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]));
        $second = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]]));
        $cn     = $this->issueCreditNote($first->id);

        $task     = $this->createTask($s['client'], ['rate' => '100.00', 'hours' => '1.00']);
        $alloc    = $this->createAllocation($task, '1.00', '2026-09', ['rate' => '100.00']);
        $external = $this->insertExternalInvoice($s['client'], '2026-09', [$alloc]);

        $clients = new ClientService($this->rw());
        $client  = $clients->find($s['client']);
        $this->assertNotNull($client);
        $clients->update($s['client'], ['name' => self::RENAMED] + $client);

        // zoznam a detail
        $queries = new InvoiceQueries($this->rw());
        $list    = $queries->list(null, null, '2026-12-01');
        $names   = array_column($list['rows'], 'client_name', 'id');
        $this->assertSame(self::ORIGINAL, $names[$first->id]);
        $this->assertSame(self::ORIGINAL, $names[$second->id]);
        $this->assertSame(self::ORIGINAL, $names[$cn->id]);
        $this->assertSame(self::RENAMED, $names[$external], 'externa faktura nema snapshot, ukazuje zivy nazov');
        $this->assertSame(self::ORIGINAL, $queries->detail($first->id)['client_name'] ?? null);
        $this->assertSame(self::ORIGINAL, $queries->detail($cn->id)['client_name'] ?? null);

        // export zoznamu (tabulka)
        $issuedRows = array_values(array_filter($list['rows'], static fn (array $r): bool => $r['id'] !== $external));
        $table      = DocumentListExport::table($issuedRows, false);
        $flat       = json_encode($table->rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString(self::ORIGINAL, $flat);
        $this->assertStringNotContainsString(self::RENAMED, $flat);

        // balik pre uctovnicku
        $period = ReportPeriod::range('2026-10', '2026-10');
        $docs   = (new BundleSelector($this->rw()))->documents($s['entity'], $period);
        $this->assertCount(3, $docs);
        $this->assertSame([self::ORIGINAL], array_values(array_unique(array_column($docs, 'client_name'))));

        $path = tempnam(sys_get_temp_dir(), 'snb');
        $this->assertIsString($path);

        try {
            file_put_contents($path, (new AccountantBundle($this->rw()))->build($s['entity'], $period));
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path));
            $csv = (string) $zip->getFromName('zoznam-dokladov.csv');
            $zip->close();
            $this->assertStringContainsString(self::ORIGINAL, $csv);
            $this->assertStringNotContainsString(self::RENAMED, $csv);
        } finally {
            unlink($path);
        }

        // prehlady
        foreach ([ReportPeriod::month('2026-10'), ReportPeriod::year(2026)] as $p) {
            $report = (new ReportCalculator($this->rw()))->forPeriod($s['entity'], $p);
            $labels = array_column($report->clients, 'client_name');
            $this->assertContains(self::ORIGINAL, $labels, $p->key);
            $this->assertNotContains(self::RENAMED, $labels, $p->key);
        }

        // po splatnosti (dobropisom zrusena faktura sa nezobrazi, druha ostava)
        $overdue = (new InvoiceStatusQueries($this->rw()))->overdue('2026-12-01', null, 50);
        $this->assertNotEmpty($overdue['rows']);
        $this->assertSame([self::ORIGINAL], array_values(array_unique(array_column($overdue['rows'], 'client_name'))));
    }
}
