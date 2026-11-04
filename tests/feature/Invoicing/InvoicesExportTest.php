<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Export\ExportFilename;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\XlsxReader;

/**
 * Export zoznamu faktur do CSV a XLSX (RPT-02, D-08).
 *
 * @internal
 */
#[Group('feature')]
final class InvoicesExportTest extends AuthTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private int $entity  = 0;
    private int $clientA = 0;
    private int $clientB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
        $this->entity  = $this->createEntity('SK');
        $this->clientA = $this->createClient(['name' => '=1+1']);
        $this->clientB = $this->createClient(['name' => 'Beta Klient']);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function login(string $group = 'admin'): void
    {
        $user = $this->createUser('owner@example.test', $group);
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function invoice(int $client, string $number, string $total, string $issue): int
    {
        $alloc = $this->createAllocation($this->createTask($client), '1.00', '2026-10', ['rate' => $total, 'amount' => $total]);

        return $this->insertIssuedInvoiceRaw($client, $this->entity, '2026-10', [$alloc], '%PDF-1.4 test', ['number' => $number, 'issue_date' => $issue]);
    }

    /**
     * 3 faktury (A 500, B 100, A 900), dobropis k prvej a externa faktura.
     */
    private function seed(): void
    {
        $first = $this->invoice($this->clientA, '202610001', '500.00', '2026-10-01');
        $this->invoice($this->clientB, '202610002', '100.00', '2026-10-03');
        $this->invoice($this->clientA, '202610003', '900.00', '2026-10-02');
        $this->insertRawCreditNote($first, ['issue_date' => '2026-10-20']);
        $alloc = $this->createAllocation($this->createTask($this->clientB), '1.00', '2026-10', ['rate' => '70.00', 'amount' => '70.00']);
        $this->insertExternalInvoice($this->clientB, '2026-10', [$alloc], 'EXT-1');
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(TestResponse $result): array
    {
        $body = (string) $result->response()->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', substr($body, 3))), static fn (string $l): bool => $l !== ''));

        return array_map(static fn (string $l): array => str_getcsv($l, ';', '"', ''), $lines);
    }

    public function testCsvAllRows(): void
    {
        $this->seed();
        $this->login();
        $result = $this->get('/invoices.csv');

        $result->assertStatus(200);
        $this->assertStringContainsString('text/csv; charset=UTF-8', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('faktury-vsetky-2026-11-04.csv', $result->response()->getHeaderLine('Content-Disposition'));

        $rows = $this->csvRows($result);
        $this->assertCount(6, $rows);
        $this->assertSame(lang('Export.colNumber'), $rows[0][0]);

        $credit = array_values(array_filter($rows, static fn (array $r): bool => str_contains(implode(';', $r), '-')
            && ($r[1] ?? '') !== '' && str_starts_with((string) ($r[9] ?? ''), '-')));
        $this->assertNotEmpty($credit, 'dobropis so zapornou sumou');
        $this->assertStringNotContainsString("'-", implode(';', $credit[0]));
    }

    public function testFiltersAndSort(): void
    {
        $this->seed();
        $this->login();

        $byClient = $this->csvRows($this->get('/invoices.csv?client=' . $this->clientB));
        $this->assertCount(3, $byClient);

        $sorted  = $this->csvRows($this->get('/invoices.csv?sort=total&dir=asc&status=issued'));
        $numbers = array_column(array_slice($sorted, 1), 0);
        $pos2    = array_search('202610002', $numbers, true);
        $pos3    = array_search('202610003', $numbers, true);
        $this->assertNotFalse($pos2);
        $this->assertNotFalse($pos3);
        $this->assertLessThan($pos3, $pos2);

        $paid = $this->csvRows($this->get('/invoices.csv?status=paid'));
        $this->assertCount(1, $paid);
    }

    public function testEntityInFilename(): void
    {
        $this->seed();
        $this->login();
        $name   = (string) db_connect()->query('SELECT name_internal FROM entities WHERE id = :id:', ['id' => $this->entity])->getRow()->name_internal;
        $result = $this->get('/invoices.csv?entity=' . $this->entity);

        $this->assertStringContainsString('faktury-' . ExportFilename::slug($name) . '-2026-11-04.csv', $result->response()->getHeaderLine('Content-Disposition'));
    }

    public function testExportIgnoresScreenLimit(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            $this->invoice($this->clientB, '2026' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), '10.00', '2026-10-01');
        }
        $this->login();

        $this->assertCount(102, $this->csvRows($this->get('/invoices.csv')));
    }

    public function testXlsxNumericCellsAndFormulaGuard(): void
    {
        $this->seed();
        $this->login();
        $result = $this->get('/invoices.xlsx');

        $result->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml.sheet', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('faktury-vsetky-2026-11-04.xlsx', $result->response()->getHeaderLine('Content-Disposition'));

        $bytes = (string) $result->response()->getBody();
        $this->assertStringNotContainsString('<f>', XlsxReader::sheetXml($bytes));
        $cells = XlsxReader::cells($bytes);
        $this->assertCount(6, $cells);

        $types = array_column($cells[1], 'type');
        $this->assertContains('n', $types);

        $texts = [];

        foreach ($cells as $row) {
            foreach ($row as $c) {
                if ($c['type'] === 'inlineStr') {
                    $texts[] = $c['value'];
                }
            }
        }
        $this->assertContains('=1+1', $texts);
    }

    public function testButtonsCarryFilters(): void
    {
        $this->seed();
        $this->login();
        $html = $this->body($this->get('/invoices?client=' . $this->clientB));

        $this->assertStringContainsString('href="/invoices.csv?client=' . $this->clientB, $html);
        $this->assertStringContainsString('href="/invoices.xlsx?', $html);
        $this->assertStringNotContainsString(lang('Export.hintAllRows'), $html);
    }

    public function testTruncatedListShowsExportHint(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            $this->invoice($this->clientB, '2026' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), '10.00', '2026-10-01');
        }
        $this->login();

        $this->assertStringContainsString(lang('Export.hintAllRows'), $this->body($this->get('/invoices')));
    }

    public function testNoButtonsOnEmptyUnfilteredList(): void
    {
        $this->login();

        $this->assertStringNotContainsString('/invoices.csv', $this->body($this->get('/invoices')));
    }

    public function testAccountantIsForbidden(): void
    {
        $this->seed();
        $this->login('accountant');
        $this->get('/invoices.csv')->assertStatus(403);
        $this->get('/invoices.xlsx')->assertStatus(403);
    }
}
