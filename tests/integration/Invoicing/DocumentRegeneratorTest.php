<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\DocumentRegenerator;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\RegenerateRejected;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CapturingPdfRenderer;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\FaultInjectingRenderer;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Tests\Support\RegenerateFixtures;

/**
 * @internal
 */
#[Group('integration')]
final class DocumentRegeneratorTest extends PgTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;
    use RegenerateFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    public function testLockKeyIsBigintSafeAndExclusive(): void
    {
        $big = 5_000_000_000;
        $a   = $this->owner();
        $b   = $this->rw();

        $a->query('BEGIN');
        $b->query('BEGIN');

        try {
            $a->query('SELECT pg_advisory_xact_lock(' . DocumentRegenerator::LOCK_KEY_SQL . ')', ['id' => $big]);

            $held = static fn ($db, int $id): bool => in_array((string) $db->query('SELECT pg_try_advisory_xact_lock(' . DocumentRegenerator::LOCK_KEY_SQL . ') AS l', ['id' => $id])->getRow()->l, ['t', '1', 'true'], true);

            $this->assertFalse($held($b, $big));
            $this->assertTrue($held($b, $big + 1));
        } finally {
            $a->query('ROLLBACK');
            $b->query('ROLLBACK');
        }
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private static function canon(array $a): array
    {
        ksort($a);

        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = self::canon($v);
            }
        }

        return $a;
    }

    private function issued(): int
    {
        $s = $this->issueSetup();

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
    }

    private function docCount(int $id, string $kind): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_documents WHERE invoice_id = :i: AND kind = :k:', ['i' => $id, 'k' => $kind])->getRow()->c;
    }

    private function auditCount(int $id): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'invoice' AND entity_id = :i: AND action = 'regenerate_document'", ['i' => $id])->getRow()->c;
    }

    private function assertRejected(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected RegenerateRejected ' . $reason);
        } catch (RegenerateRejected $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function testRegeneratesReport(): void
    {
        $id  = $this->issued();
        $res = $this->regenerator()->regenerate($id, 'report', 1);

        $this->assertSame(2, $res->version);
        $this->assertSame('rep-2', $res->templateVersion);
        $this->assertSame(1, $res->previousVersion);
        $this->assertSame(2, (new DocumentStore($this->rw()))->meta($id, 'report')['version']);
        $this->assertSame(2, $this->docCount($id, 'report'));
    }

    public function testLegacyRep1ReportGetsCurrentTemplate(): void
    {
        $s       = $this->issueSetup();
        $id      = $this->insertLegacyRep1Invoice($s['client'], $s['entity'], '2026-10', $s['parts']);
        $capture = new CapturingPdfRenderer('rep-2');
        $res     = $this->regenerator(['rep-2' => $capture])->regenerate($id, 'report', 1);

        $this->assertSame(2, $res->version);
        $this->assertSame('rep-1', $res->previousTemplateVersion);
        $this->assertSame('rep-2', (new DocumentStore($this->rw()))->meta($id, 'report')['template_version']);
        $this->assertSame(1, $capture->calls);
    }

    public function testOriginalVersionUnchanged(): void
    {
        $id    = $this->issued();
        $store = new DocumentStore($this->rw());
        $v1    = $store->readVersion($id, 'report', 1);
        $row1  = $this->owner()->query("SELECT created_at FROM invoice_documents WHERE invoice_id = :i: AND kind = 'report' AND version = 1", ['i' => $id])->getRow()->created_at;

        $this->regenerator()->regenerate($id, 'report', 1);

        $after = $store->readVersion($id, 'report', 1);
        $this->assertNotNull($v1);
        $this->assertNotNull($after);
        $this->assertSame($v1->bytes, $after->bytes);
        $this->assertSame($v1->sha256, $after->sha256);
        $this->assertSame($v1->templateVersion, $after->templateVersion);
        $this->assertSame($row1, $this->owner()->query("SELECT created_at FROM invoice_documents WHERE invoice_id = :i: AND kind = 'report' AND version = 1", ['i' => $id])->getRow()->created_at);
    }

    public function testRegeneratesInvoiceWithinWindow(): void
    {
        $id  = $this->issued();
        $res = $this->regenerator()->regenerate($id, 'invoice', 1);

        $this->assertSame(2, $res->version);
        $this->assertSame('inv-2', $res->templateVersion);
    }

    public function testNotRegenerableExternal(): void
    {
        $s  = $this->issueSetup();
        $id = $this->insertExternalInvoice($s['client'], '2026-10', $s['parts']);

        foreach (['invoice', 'report'] as $kind) {
            $this->assertRejected('not_regenerable', fn () => $this->regenerator()->regenerate($id, $kind, 1));
        }
    }

    public function testNotRegenerableSchemaOne(): void
    {
        $s  = $this->issueSetup();
        $id = $this->insertIssuedInvoiceRaw($s['client'], $s['entity'], '2026-10', $s['parts']);

        $this->assertRejected('not_regenerable', fn () => $this->regenerator()->regenerate($id, 'invoice', 1));
        $this->assertSame(1, $this->docCount($id, 'invoice'));
    }

    public function testNotRegenerableCreditNoteReport(): void
    {
        $cn = $this->issueCreditNote($this->issued());

        $this->assertRejected('not_regenerable', fn () => $this->regenerator()->regenerate($cn->id, 'report', 1));
    }

    public function testCreditNoteInvoicePdf(): void
    {
        $cn  = $this->issueCreditNote($this->issued());
        $res = $this->regenerator()->regenerate($cn->id, 'invoice', 1);

        $this->assertSame(2, $res->version);
        $this->assertSame('cn-1', $res->templateVersion);
    }

    public function testNotFound(): void
    {
        $this->assertRejected('not_found', fn () => $this->regenerator()->regenerate(999999, 'report', 1));
    }

    public function testStaleExpectedVersion(): void
    {
        $id = $this->issued();
        $this->regenerator()->regenerate($id, 'report', 1);

        $this->assertRejected('stale', fn () => $this->regenerator()->regenerate($id, 'report', 1));
        $this->assertSame(2, $this->docCount($id, 'report'));
    }

    public function testRenderFailureWritesNothing(): void
    {
        $id = $this->issued();

        $this->assertRejected('render_failed', fn () => $this->regenerator(['rep-2' => new FaultInjectingRenderer()])->regenerate($id, 'report', 1));
        $this->assertSame(1, $this->docCount($id, 'report'));
        $this->assertSame(0, $this->auditCount($id));
    }

    public function testTemplateMismatchRejected(): void
    {
        $id = $this->issued();

        $this->assertRejected('render_failed', fn () => $this->regenerator(['rep-2' => new CapturingPdfRenderer('rep-9')])->regenerate($id, 'report', 1));
        $this->assertSame(1, $this->docCount($id, 'report'));
        $this->assertSame(0, $this->auditCount($id));
    }

    public function testAuditRecorded(): void
    {
        $id  = $this->issued();
        $old = (new DocumentStore($this->rw()))->meta($id, 'report');
        $res = $this->regenerator()->regenerate($id, 'report', 1);

        $row = $this->owner()->query("SELECT before_data, after_data FROM audit_log WHERE entity_type = 'invoice' AND entity_id = :i: AND action = 'regenerate_document'", ['i' => $id])->getRowArray();
        $this->assertNotNull($row);
        $this->assertNotNull($old);

        $b = json_decode((string) $row['before_data'], true, 512, JSON_THROW_ON_ERROR);
        $a = json_decode((string) $row['after_data'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(self::canon(['kind' => 'report', 'version' => 1, 'template_version' => $old['template_version'], 'sha256' => $old['sha256']]), self::canon($b));
        $this->assertSame(self::canon(['kind' => 'report', 'version' => 2, 'template_version' => 'rep-2', 'sha256' => $res->sha256]), self::canon($a));
    }
}
