<?php

declare(strict_types=1);

namespace Tests\Integration\Export;

use App\Libraries\Export\YearArchive;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Reporting\BundleSelector;
use App\Libraries\Reporting\ReportCalculator;
use App\Libraries\Reporting\ReportPeriod;
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
use Tests\Support\TurnoverFixtures;
use ZipArchive;

/**
 * D-08: rocny archiv vyberá podla datumu vystavenia (issue_date), prehlady a balik podla datumu dodania (turnover_date).
 * Dobropis vystaveny v januari 2027 k faktúre s dodanim v decembri 2026 je v decembrovom prehlade a baliku
 * a v archive roka 2027, nie 2026.
 *
 * @internal
 */
#[Group('integration')]
final class DateBasisTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use CreditNoteFixtures;
    use TurnoverFixtures;
    use ReportFixtures;

    private const CN_NUMBER = 'DB-2027-1';

    private int $entity;
    private int $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        $this->removeArchive();
        Services::injectMock('clock', new FrozenClock('2027-01-12T10:00:00+01:00'));
        $this->entity   = $this->createEntity('SK', ['active_from' => '2024-01-01']);
        $client         = $this->createClient(['name' => 'Klient']);
        $this->original = $this->reportInvoice($this->entity, $client, '2026-12-20', '100.00');
        $this->insertRawCreditNote($this->original, ['issue_date' => '2027-01-10', 'turnover_date' => '2026-12-20', 'number' => self::CN_NUMBER]);
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

    private function archiveCsv(int $year): string
    {
        $result = (new YearArchive($this->rw()))->build($year);
        $zip    = new ZipArchive();
        $this->assertTrue($zip->open($result->path));
        $csv = (string) $zip->getFromName('zoznam-dokladov.csv');
        $zip->close();

        return $csv;
    }

    public function testReportsAndBundleSelectByTurnoverDate(): void
    {
        $calc = new ReportCalculator($this->rw());

        $dec = $calc->forPeriod($this->entity, ReportPeriod::month('2026-12'));
        $this->assertSame(1, $dec->creditNoteCount);
        $this->assertSame('0.00', $dec->invoicedTotal);

        $jan = $calc->forPeriod($this->entity, ReportPeriod::month('2027-01'));
        $this->assertSame(0, $jan->creditNoteCount);

        $sel = new BundleSelector($this->rw());
        $this->assertContains(self::CN_NUMBER, array_column($sel->documents($this->entity, ReportPeriod::range('2026-12', '2026-12')), 'number'));
        $this->assertNotContains(self::CN_NUMBER, array_column($sel->documents($this->entity, ReportPeriod::range('2027-01', '2027-01')), 'number'));
    }

    public function testYearArchiveSelectsByIssueDate(): void
    {
        $this->assertStringContainsString(self::CN_NUMBER, $this->archiveCsv(2027));

        $csv2026 = $this->archiveCsv(2026);
        $this->assertStringNotContainsString(self::CN_NUMBER, $csv2026);
        $number = (string) $this->rw()->query('SELECT number FROM invoices WHERE id = :i:', ['i' => $this->original])->getRow()->number;
        $this->assertStringContainsString($number, $csv2026, 'povodna faktura je v archive 2026');
    }
}
