<?php

declare(strict_types=1);

namespace Tests\Integration\Work;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\ClientService;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Money;
use App\Libraries\Work\Import\ExcelReader;
use App\Libraries\Work\Import\ImportPlan;
use App\Libraries\Work\Import\ImportPlanner;
use App\Libraries\Work\Import\ImportRow;
use App\Libraries\Work\Import\ImportRunner;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ImportFixture;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class ImportExcelTest extends PgTestCase
{
    use MasterFixtures;
    use StreamFilterTrait;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
        $this->file = WRITEPATH . 'tests/import-fixture.xlsx';
        ImportFixture::write($this->file);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function runCommand(int $client, bool $commit, string $extra = ''): string
    {
        $this->resetStreamFilterBuffer();
        command('import:excel ' . $this->file . ' --client ' . $client . ($extra !== '' ? ' ' . $extra : '') . ($commit ? ' --commit' : ''));

        return $this->getStreamFilterBuffer();
    }

    private function rows(string $table): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM ' . $table)->getRow()->c;
    }

    public function testDryRunReportsDifferencesAndWritesNothing(): void
    {
        $client = $this->createClient();
        $out    = $this->runCommand($client, false);

        $this->assertStringContainsString('2026', $out);
        $this->assertStringContainsString('september', mb_strtolower($out));
        $this->assertStringContainsString('október', mb_strtolower($out));
        $this->assertStringContainsString('F14:F16', $out);
        $this->assertStringContainsString('riadok 15', $out);
        $this->assertStringContainsString('Riadky bez špecifického odkazu', $out);
        $this->assertStringContainsString('Rozdiely: áno', $out);
        $this->assertSame(0, $this->rows('tasks'));
        $this->assertSame(0, $this->rows('billing_allocations'));
        $this->assertSame(0, $this->rows('audit_log WHERE entity_type = \'task\''));
    }

    public function testCommitCreatesTasksAndUnlockedPartsForDoneRows(): void
    {
        $client = $this->createClient();
        $out    = $this->runCommand($client, true);

        $this->assertStringContainsString('Vytvorené: 10', $out);
        $this->assertSame(10, $this->rows('tasks'));

        $done = $this->owner()->query("SELECT t.id, t.title, t.url, count(a.id) AS parts, sum(a.hours) AS h, min(a.amount) AS amount, min(a.currency) AS cur, min(a.billing_month) AS m, count(a.invoice_id) AS locked FROM tasks t LEFT JOIN billing_allocations a ON a.task_id = t.id WHERE t.status = 'done' GROUP BY t.id ORDER BY t.title")->getResultArray();
        $this->assertCount(7, $done);

        foreach ($done as $row) {
            $this->assertSame(1, (int) $row['parts']);
            $this->assertSame(0, (int) $row['locked']);
            $this->assertSame('EUR', $row['cur']);
            $this->assertSame(Money::of('27.00')->timesHours((string) $row['h'])->toDecimalString(), $row['amount']);
        }

        $open = $this->owner()->query("SELECT count(*) AS c FROM tasks t WHERE t.status = 'in_progress' AND NOT EXISTS (SELECT 1 FROM billing_allocations a WHERE a.task_id = t.id)")->getRow();
        $this->assertSame(3, (int) $open->c);

        $months  = $this->owner()->query("SELECT t.title, a.billing_month FROM tasks t JOIN billing_allocations a ON a.task_id = t.id WHERE t.title IN ('Úloha A', 'Úloha H')")->getResultArray();
        $byTitle = array_column($months, 'billing_month', 'title');
        $this->assertSame('2026-09', $byTitle['Úloha A']);
        $this->assertSame('2026-10', $byTitle['Úloha H']);

        $urls = array_column($this->owner()->query('SELECT title, url FROM tasks')->getResultArray(), 'url', 'title');
        $this->assertSame('https://taskman.example/?section=management&project=1&todo=1', $urls['Úloha A']);
        $this->assertSame('https://taskman.example/?section=mana', $urls['Úloha D']);
        $this->assertNull($urls['Úloha E']);

        $this->assertSame(10, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'task' AND action = 'create'")->getRow()->c);
        $this->assertSame(7, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'task' AND action = 'mark_done'")->getRow()->c);
    }

    public function testSecondCommitCreatesNothing(): void
    {
        $client = $this->createClient();
        $this->runCommand($client, true);
        $out = $this->runCommand($client, true);

        $this->assertStringContainsString('Vytvorené: 0', $out);
        $this->assertSame(10, $this->rows('tasks'));
        $this->assertSame(7, $this->rows('billing_allocations'));
    }

    public function testClientWithDifferentRateReportsMismatchAndUsesClientRate(): void
    {
        $client = $this->createClient(['hourly_rate' => '30.00']);
        $dry    = $this->runCommand($client, false);
        $this->assertStringContainsString('Rozdiel sadzby', $dry);

        $this->runCommand($client, true);
        $row = $this->owner()->query('SELECT a.hours, a.amount FROM billing_allocations a ORDER BY a.id LIMIT 1')->getRow();
        $this->assertSame(Money::of('30.00')->timesHours((string) $row->hours)->toDecimalString(), $row->amount);
    }

    public function testMonthDryRunShowsOnlySeptemberAndWritesNothing(): void
    {
        $client = $this->createClient();
        $out    = mb_strtolower($this->runCommand($client, false, '--month 2026-09'));

        $this->assertStringContainsString('september', $out);
        $this->assertStringNotContainsString('október', $out);
        $this->assertStringNotContainsString('f14:f16', $out);
        $this->assertStringNotContainsString('riadok 15', $out);
        $this->assertSame(0, $this->rows('tasks'));
        $this->assertSame(0, $this->rows('billing_allocations'));
        $this->assertSame(0, $this->rows('audit_log WHERE entity_type = \'task\''));
    }

    public function testMonthCommitCreatesOnlySeptemberAndKeepsImportKeys(): void
    {
        $client = $this->createClient();
        $out    = $this->runCommand($client, true, '--month 2026-09');

        $this->assertStringContainsString('Vytvorené: 5', $out);
        $this->assertSame(5, $this->rows('tasks'));
        $this->assertSame(0, $this->rows("tasks WHERE title IN ('Úloha F', 'Úloha G', 'Úloha H', 'Úloha I', 'Úloha J')"));
        $this->assertSame(4, $this->rows('billing_allocations'));
        $this->assertSame(4, $this->rows("billing_allocations WHERE billing_month = '2026-09'"));

        $full     = (new ImportPlanner())->plan((new ExcelReader())->read($this->file), '27.00', 'EUR');
        $expected = [];

        foreach ($full->items as $item) {
            if ($item['row']->month === '2026-09') {
                $expected[] = $item['importKey'];
            }
        }

        $actual = array_column($this->owner()->query('SELECT import_key FROM tasks')->getResultArray(), 'import_key');
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);

        $again = $this->runCommand($client, true, '--month 2026-09');
        $this->assertStringContainsString('Vytvorené: 0', $again);

        $rest = $this->runCommand($client, true);
        $this->assertStringContainsString('Vytvorené: 5, preskočené (už importované): 5', $rest);
        $this->assertSame(10, $this->rows('tasks'));
        $this->assertSame(7, $this->rows('billing_allocations'));
    }

    public function testInvalidOrMissingMonthWritesNothing(): void
    {
        $client = $this->createClient();

        foreach (['--month 2026-9', '--month 2026-13', '--month sep', '--month', '--month 2026-11'] as $extra) {
            $out = $this->runCommand($client, true, $extra);

            $this->assertStringNotContainsString('Vytvorené', $out, $extra);
            $this->assertTrue(
                str_contains($out, 'Neplatný mesiac') || str_contains($out, '2026-11'),
                $extra . ' => ' . $out,
            );
            $this->assertSame(0, $this->rows('tasks'), $extra);
            $this->assertSame(0, $this->rows('billing_allocations'), $extra);
            $this->assertSame(0, $this->rows('audit_log WHERE entity_type = \'task\''), $extra);
        }
    }

    public function testRealFileSingleMonthImport(): void
    {
        $real = ROOTPATH . 'docs/All4net-fakturovanie-prace.xlsx';

        if (! is_file($real)) {
            $this->markTestSkipped('Zdrojový Excel nie je k dispozícii.');
        }

        $client     = $this->createClient();
        $this->file = $real;

        $dry = $this->runCommand($client, false, '--month 2026-09');
        $this->assertStringContainsString('september', mb_strtolower($dry));
        $this->assertStringNotContainsString('október', mb_strtolower($dry));
        $this->assertSame(0, $this->rows('tasks'));

        $this->runCommand($client, true, '--month 2026-09');
        $this->assertSame(11, $this->rows('tasks'));
        $this->assertSame(11, $this->rows("tasks WHERE status = 'done'"));
        $this->assertSame(11, $this->rows("billing_allocations WHERE billing_month = '2026-09' AND invoice_id IS NULL"));
        $this->assertSame(11, $this->rows('billing_allocations'));
        $sum = $this->owner()->query('SELECT sum(hours)::text AS h, sum(amount)::text AS a FROM billing_allocations')->getRow();
        $this->assertSame('89.00', $sum->h);
        $this->assertSame('2403.00', $sum->a);
        $this->assertSame(0, $this->rows('tasks t WHERE NOT EXISTS (SELECT 1 FROM billing_allocations a WHERE a.task_id = t.id)'));

        $rest = $this->runCommand($client, true);
        $this->assertStringContainsString('Vytvorené: 5, preskočené (už importované): 11', $rest);
        $this->assertSame(12, $this->rows('billing_allocations'));
    }

    public function testMissingFileWritesNothing(): void
    {
        $client     = $this->createClient();
        $this->file = WRITEPATH . 'tests/does-not-exist.xlsx';
        $out        = $this->runCommand($client, true);

        $this->assertStringContainsString('neexistuje', $out);
        $this->assertSame(0, $this->rows('tasks'));
    }

    public function testInvalidItemRollsBackWholeImport(): void
    {
        $client          = $this->createClient();
        $plan            = (new ImportPlanner())->plan((new ExcelReader())->read($this->file), '27.00', 'EUR');
        $items           = $plan->items;
        $bad             = $items[3]['row'];
        $items[3]['row'] = new ImportRow($bad->rowNumber, $bad->month, $bad->url, $bad->specificLink, '   ', $bad->hours, $bad->excelAmount, $bad->done, $bad->billed);
        $broken          = new ImportPlan($items, $plan->months, $plan->rowIssues, $plan->rateMismatch, $plan->genericLinkRows);

        try {
            (new ImportRunner($this->rw(), new AuditLogger($this->rw())))->run($broken, $client);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString((string) $bad->rowNumber, $e->getMessage());
        }

        $this->assertSame(0, $this->rows('tasks'));
        $this->assertSame(0, $this->rows('billing_allocations'));
    }

    public function testArchivedClientIsRejected(): void
    {
        $client = $this->createClient();
        (new ClientService($this->rw(), new AuditLogger($this->rw())))->archive($client);
        $plan = (new ImportPlanner())->plan((new ExcelReader())->read($this->file), '27.00', 'EUR');

        $this->expectException(InvalidArgumentException::class);
        (new ImportRunner($this->rw(), new AuditLogger($this->rw())))->run($plan, $client);
    }

    public function testRunnerAndFormatterDoNotTouchPhpSpreadsheet(): void
    {
        foreach (['ImportRunner.php', 'ImportReportFormatter.php'] as $f) {
            $this->assertStringNotContainsString('PhpOffice', (string) file_get_contents(APPPATH . 'Libraries/Work/Import/' . $f));
        }
    }
}
