<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Invoicing\IssueCommand;
use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Invoicing\PdfRenderer;
use App\Libraries\Invoicing\RenderedPdf;
use App\Libraries\Invoicing\RendererRegistry;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakePdfRenderer;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class IssueIdempotencyTest extends PgTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

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

    private function invoiceCount(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM invoices')->getRow()->c;
    }

    private function rejection(callable $fn): IssueRejected
    {
        try {
            $fn();
        } catch (IssueRejected $e) {
            return $e;
        }

        $this->fail('Expected IssueRejected');
    }

    public function testSameRequestKeyReturnsFirstInvoice(): void
    {
        $s     = $this->issueSetup();
        $cmd   = $this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']);
        $first = $this->issuer()->issue($cmd);

        $e = $this->rejection(fn () => $this->issuer()->issue($cmd));

        $this->assertSame('already_issued', $e->reason);
        $this->assertSame($first->id, $e->invoiceId);
        $this->assertSame($first->number, $e->invoiceNumber);
        $this->assertSame(1, $this->invoiceCount());
    }

    /**
     * pg_connect s rovnakym DSN vrati to iste fyzicke spojenie; iny application_name vynuti nove (inak by
     * "subezny" poziadavok bezal v transakcii testu a jeho COMMIT by commitol aj ju).
     *
     * @return BaseConnection<mixed, mixed>
     */
    private function separateRwConnection(): BaseConnection
    {
        $cfg        = config(Database::class)->tests;
        $cfg['DSN'] = sprintf(
            "host=%s port=%s user=%s password='%s' dbname=%s application_name=wr08_concurrent",
            $cfg['hostname'],
            (string) $cfg['port'],
            $cfg['username'],
            $cfg['password'],
            $cfg['database'],
        );

        return Database::connect($cfg, false);
    }

    public function testRequestKeyUniqueViolationMapsToAlreadyIssued(): void
    {
        // WR-08: subezny poziadavok s rovnakym klucom commitne skor (iny rad, takze bez cakania na zamok radu).
        // Pomaly poziadavok potom narazi na invoices_request_key_key a namiesto 500 dostane already_issued.
        $a = $this->issueSetup();
        $b = $this->issueSetup(['country' => 'PL', 'client' => ['name' => 'Klient PL', 'identifier_value' => '7010000001']]);
        $this->owner()->query("UPDATE entity_versions SET tax_id = '1234563218' WHERE entity_id = :e:", ['e' => $b['entity']]);

        $key   = $this->uuid4();
        $slow  = $this->issueCommand($a['client'], '2026-10', $a['entity'], $a['parts'], ['requestKey' => $key]);
        $quick = $this->issueCommand($b['client'], '2026-10', $b['entity'], $b['parts'], ['requestKey' => $key]);
        $other = $this->separateRwConnection();

        $hook = new class ($other, $quick) implements PdfRenderer {
            public ?int $quickId = null;

            /**
             * @param BaseConnection<mixed, mixed> $db
             */
            public function __construct(private readonly BaseConnection $db, private readonly IssueCommand $cmd)
            {
            }

            public function render(array $snapshot): RenderedPdf
            {
                if ($this->quickId === null) {
                    $this->quickId = (new InvoiceIssuer($this->db, new AuditLogger($this->db), new RendererRegistry(['inv-2' => new FakePdfRenderer('inv-2'), 'rep-1' => new FakePdfRenderer('rep-1')])))->issue($this->cmd)->id;
                }

                return (new FakePdfRenderer())->render($snapshot);
            }
        };

        try {
            $e = $this->rejection(fn () => $this->issuer($hook)->issue($slow));
        } finally {
            $other->close();
        }

        $this->assertSame('already_issued', $e->reason);
        $this->assertNotNull($hook->quickId);
        $this->assertSame($hook->quickId, $e->invoiceId);
        $this->assertSame(1, $this->invoiceCount());
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations WHERE invoice_id IS NOT NULL AND id = ANY(CAST(:ids: AS bigint[]))', ['ids' => '{' . implode(',', $a['parts']) . '}'])->getRow()->c);
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_counters WHERE series_id = :s:', ['s' => $a['series']])->getRow()->c);
    }

    public function testNewRequestKeyForLockedPartsMentionsInvoiceNumber(): void
    {
        $s = $this->issueSetup();
        $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        $e = $this->rejection(fn () => $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'])));

        $this->assertSame('parts_changed', $e->reason);
        $this->assertSame('202610001', $e->invoiceNumber);
        $this->assertSame(1, $this->invoiceCount());
    }

    public function testChangedHoursAfterPreviewAreRejectedAsStale(): void
    {
        $s   = $this->issueSetup();
        $cmd = $this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']);
        $this->owner()->query("UPDATE billing_allocations SET hours = '41.00', amount = '1107.00' WHERE id = :id:", ['id' => $s['parts'][0]]);

        $e = $this->rejection(fn () => $this->issuer()->issue($cmd));

        $this->assertSame('preview_stale', $e->reason);
        $this->assertSame(0, $this->invoiceCount());
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_counters')->getRow()->c);
    }

    public function testPartMovedToAnotherMonthIsRejectedWithoutInvoiceId(): void
    {
        $s   = $this->issueSetup();
        $cmd = $this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']);
        $this->owner()->query("UPDATE billing_allocations SET billing_month = '2026-11' WHERE id = :id:", ['id' => $s['parts'][0]]);

        $e = $this->rejection(fn () => $this->issuer()->issue($cmd));

        $this->assertSame('parts_changed', $e->reason);
        $this->assertNull($e->invoiceId);
        $this->assertSame(0, $this->invoiceCount());
    }
}
