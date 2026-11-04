<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

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
final class RegenerateConcurrencyTest extends PgTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->owner()->query('SELECT pg_advisory_unlock_all()');
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @param list<string> $kinds druh dokumentu pre kazdeho workera
     *
     * @return list<array<string, mixed>>
     */
    private function runWorkers(int $invoiceId, array $kinds): array
    {
        $procs = [];

        $this->owner()->query('SELECT pg_advisory_lock(777)');

        foreach ($kinds as $i => $kind) {
            $arg  = json_encode(['invoice' => $invoiceId, 'kind' => $kind, 'expected' => 1], JSON_THROW_ON_ERROR);
            $proc = proc_open(
                [PHP_BINARY, ROOTPATH . 'tests/_support/regenerate_worker.php', (string) $i, '--command', $arg],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }

        $this->releaseStartLock(count($kinds));

        $outputs = [];

        foreach ($procs as [$proc, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), 'worker failed: ' . $out . $err);
            $o = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([], $o['errors']);
            $outputs[] = $o;
        }

        return $outputs;
    }

    private function issued(): int
    {
        $s = $this->issueSetup();

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
    }

    private function maxVersion(int $id, string $kind): int
    {
        return (int) $this->owner()->query('SELECT max(version) AS v FROM invoice_documents WHERE invoice_id = :i: AND kind = :k:', ['i' => $id, 'k' => $kind])->getRow()->v;
    }

    public function testTwoProcessesCreateExactlyOneNewVersion(): void
    {
        $id      = $this->issued();
        $outputs = $this->runWorkers($id, ['report', 'report']);

        $versions = [];
        $rejected = [];

        foreach ($outputs as $o) {
            $versions = array_merge($versions, $o['versions']);
            $rejected = array_merge($rejected, $o['rejected']);
        }

        $this->assertSame([2], $versions);
        $this->assertCount(1, $rejected);
        $this->assertSame('stale', $rejected[0]['reason']);
        $this->assertSame(2, (int) $this->owner()->query("SELECT count(*) AS c FROM invoice_documents WHERE invoice_id = :i: AND kind = 'report'", ['i' => $id])->getRow()->c);
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'invoice' AND entity_id = :i: AND action = 'regenerate_document'", ['i' => $id])->getRow()->c);
    }

    public function testDifferentKindsDoNotConflict(): void
    {
        $id      = $this->issued();
        $outputs = $this->runWorkers($id, ['report', 'invoice']);

        foreach ($outputs as $o) {
            $this->assertCount(1, $o['versions']);
            $this->assertSame([], $o['rejected']);
        }

        $this->assertSame(2, $this->maxVersion($id, 'report'));
        $this->assertSame(2, $this->maxVersion($id, 'invoice'));
    }

    private function releaseStartLock(int $expected): void
    {
        $waiting = 0;

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $waiting = (int) $this->owner()->query("SELECT count(*) AS c FROM pg_locks WHERE locktype = 'advisory' AND NOT granted")->getRow()->c;

            if ($waiting === $expected) {
                break;
            }
            usleep(50_000);
        }

        $this->owner()->query('SELECT pg_advisory_unlock(777)');
        $this->assertSame($expected, $waiting, 'all workers should be waiting for the start lock');
    }
}
