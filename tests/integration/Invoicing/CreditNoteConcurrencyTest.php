<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class CreditNoteConcurrencyTest extends PgTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));
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
     * @return array{outputs: list<array<string, mixed>>}
     */
    private function runWorkers(int $originalId, string $keyA, string $keyB): array
    {
        $token = $this->creditNoteCommand($originalId)->previewToken;
        $procs = [];

        $this->owner()->query('SELECT pg_advisory_lock(777)');

        foreach ([0 => $keyA, 1 => $keyB] as $i => $key) {
            $arg = json_encode([
                'original' => $originalId, 'issue' => '2026-10-31', 'reason' => 'Chybná sadzba',
                'type'     => 'amount_error', 'token' => $token, 'key' => $key,
            ], JSON_THROW_ON_ERROR);

            $proc = proc_open(
                [PHP_BINARY, ROOTPATH . 'tests/_support/credit_note_worker.php', (string) $i, '--command', $arg],
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
            $o = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([], $o['errors']);
            $outputs[] = $o;
        }

        return ['outputs' => $outputs];
    }

    public function testTwoProcessesWithDifferentKeysIssueExactlyOneCreditNote(): void
    {
        $s    = $this->issueSetup(['parts' => [['8.00', '2026-10'], ['2.00', '2026-10']]]);
        $orig = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;

        $r        = $this->runWorkers($orig, $this->uuid4(), $this->uuid4());
        $numbers  = [];
        $rejected = [];

        foreach ($r['outputs'] as $o) {
            $numbers  = array_merge($numbers, $o['numbers']);
            $rejected = array_merge($rejected, $o['rejected']);
        }

        $this->assertCount(1, $numbers);
        $this->assertCount(1, $rejected);
        $this->assertSame('already_credited', $rejected[0]['reason']);
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM invoices WHERE type = 'credit_note'")->getRow()->c);

        // Rad dobropisov nema medzeru: dalsi dobropis na inu fakturu dostane poradie +1.
        $cnNo = (int) substr($numbers[0], -3);
        $s2   = $this->createClient(['name' => 'Druhy', 'identifier_value' => '20000001']);
        $t2   = $this->createTask($s2);
        $p2   = $this->createAllocation($t2, '3.00', '2026-10');
        $inv2 = $this->issuer()->issue($this->issueCommand($s2, '2026-10', $s['entity'], [$p2]))->id;
        $cn2  = $this->issueCreditNote($inv2);

        $this->assertSame($cnNo + 1, (int) substr($cn2->number, -3));
    }

    public function testTwoProcessesWithSameKeyIssueExactlyOneCreditNote(): void
    {
        $s    = $this->issueSetup(['parts' => [['8.00', '2026-10']]]);
        $orig = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
        $key  = $this->uuid4();

        $r        = $this->runWorkers($orig, $key, $key);
        $numbers  = [];
        $rejected = [];

        foreach ($r['outputs'] as $o) {
            $numbers  = array_merge($numbers, $o['numbers']);
            $rejected = array_merge($rejected, $o['rejected']);
        }

        $cnId = (int) $this->owner()->query("SELECT id FROM invoices WHERE type = 'credit_note'")->getRow()->id;

        $this->assertCount(1, $numbers);
        $this->assertCount(1, $rejected);
        $this->assertContains($rejected[0]['reason'], ['already_issued', 'already_credited']);
        $this->assertSame($cnId, $rejected[0]['credit_note_id']);
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM invoices WHERE type = 'credit_note'")->getRow()->c);
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
