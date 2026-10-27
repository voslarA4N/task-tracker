<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class IssueConcurrencyTest extends PgTestCase
{
    use IssueFixtures;
    use MasterFixtures;

    private const COUNT = 50;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->owner()->query('SELECT pg_advisory_unlock_all()');
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    public function testFiftyConcurrentIssuesOnTwoProcessesAreGapless(): void
    {
        $first   = $this->issueSetup(['parts' => [['1.00', '2026-10']]]);
        $clients = [$first['client']];

        for ($i = 2; $i <= self::COUNT; $i++) {
            $client    = $this->createClient(['name' => 'Klient ' . $i, 'identifier_value' => (string) (10000000 + $i)]);
            $task      = $this->createTask($client);
            $clients[] = $client;
            $this->createAllocation($task, '1.00', '2026-10');
        }

        $this->owner()->query('SELECT pg_advisory_lock(777)');

        $procs  = [];
        $chunks = array_chunk($clients, intdiv(self::COUNT, 2));

        foreach ($chunks as $i => $chunk) {
            $proc = proc_open(
                [PHP_BINARY, ROOTPATH . 'tests/_support/issue_worker.php', (string) $i, implode(',', $chunk), '2026-10', '2026-10-27', (string) $first['entity']],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }

        $this->releaseStartLock();

        $outputs = [];

        foreach ($procs as [$proc, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), 'worker failed: ' . $out . $err);
            $outputs[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        }

        $all = [];

        foreach ($outputs as $o) {
            $this->assertSame([], $o['errors']);
            $this->assertSame([], $o['rejected']);
            $this->assertNotEmpty($o['numbers']);
            $all = array_merge($all, $o['numbers']);
        }

        $this->assertCount(self::COUNT, $all);

        $numbers  = array_map(static fn (object $r): string => (string) $r->number, $this->owner()->query('SELECT number FROM invoices ORDER BY number')->getResult());
        $expected = [];

        for ($n = 1; $n <= self::COUNT; $n++) {
            $expected[] = '202610' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        }

        $this->assertSame($expected, $numbers);
        $this->assertContains('202610050', $numbers);
        $this->assertSame(self::COUNT, (int) $this->owner()->query('SELECT count(DISTINCT number) AS c FROM invoices')->getRow()->c);
        $this->assertSame(self::COUNT, (int) $this->owner()->query('SELECT last_value FROM invoice_counters')->getRow()->last_value);
        $this->assertSame(self::COUNT, (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations WHERE invoice_id IS NOT NULL')->getRow()->c);
    }

    public function testSameCommandOnTwoProcessesIssuesExactlyOneInvoice(): void
    {
        // WR-08: dvojite odoslanie toho isteho prikazu (rovnaky kluc aj casti) subezne v dvoch procesoch.
        $s   = $this->issueSetup(['parts' => [['8.00', '2026-10'], ['2.00', '2026-10']]]);
        $cmd = $this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']);
        $arg = json_encode([
            'client' => $cmd->clientId, 'month' => $cmd->billingMonth, 'entity' => $cmd->entityId,
            'issue'  => $cmd->issueDate, 'delivery' => $cmd->deliveryDate, 'due' => $cmd->dueDate,
            'parts'  => $cmd->partIds, 'token' => $cmd->previewToken, 'key' => $cmd->requestKey,
        ], JSON_THROW_ON_ERROR);

        $this->owner()->query('SELECT pg_advisory_lock(777)');

        $procs = [];

        foreach ([0, 1] as $i) {
            $proc = proc_open(
                [PHP_BINARY, ROOTPATH . 'tests/_support/issue_worker.php', (string) $i, '--command', $arg],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }

        $this->releaseStartLock();

        $numbers  = [];
        $rejected = [];

        foreach ($procs as [$proc, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), 'worker failed: ' . $out . $err);
            $o = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([], $o['errors']);
            $numbers  = array_merge($numbers, $o['numbers']);
            $rejected = array_merge($rejected, $o['rejected']);
        }

        $invoiceId = (int) $this->owner()->query('SELECT id FROM invoices')->getRow()->id;

        $this->assertSame(['202610001'], $numbers);
        $this->assertSame([['reason' => 'already_issued', 'invoice_id' => $invoiceId]], $rejected);
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM invoices')->getRow()->c);
        $this->assertSame(1, (int) $this->owner()->query('SELECT last_value FROM invoice_counters')->getRow()->last_value);
        $this->assertSame(2, (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations WHERE invoice_id = :i:', ['i' => $invoiceId])->getRow()->c);
    }

    private function releaseStartLock(): void
    {
        $waiting = 0;

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $waiting = (int) $this->owner()->query("SELECT count(*) AS c FROM pg_locks WHERE locktype = 'advisory' AND NOT granted")->getRow()->c;

            if ($waiting === 2) {
                break;
            }
            usleep(50_000);
        }

        $this->owner()->query('SELECT pg_advisory_unlock(777)');
        $this->assertSame(2, $waiting, 'both workers should be waiting for the start lock');
    }
}
