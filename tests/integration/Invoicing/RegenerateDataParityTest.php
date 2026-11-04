<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CapturingPdfRenderer;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Tests\Support\RegenerateFixtures;

/**
 * Vstup renderu pri pregenerovani == invoices.snapshot (D-09, D-10). PDF bajty sa neporovnavaju.
 *
 * @internal
 */
#[Group('integration')]
final class RegenerateDataParityTest extends PgTestCase
{
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

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = $this->owner()->query('SELECT snapshot, total, template_version FROM invoices WHERE id = :i:', ['i' => $id])->getRowArray();
        $this->assertNotNull($row);

        return $row;
    }

    public function testRenderInputEqualsSnapshotEvenAfterLiveDataChange(): void
    {
        $s         = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.50', '2026-10']]]);
        $issueInv  = new CapturingPdfRenderer('inv-2');
        $issueRep  = new CapturingPdfRenderer('rep-2');
        $id        = $this->issuer($issueInv, $issueRep)->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
        $issuedInv = $issueInv->lastSnapshot;
        $issuedRep = $issueRep->lastSnapshot;
        $before    = $this->row($id);

        $this->owner()->query("UPDATE clients SET name = 'Iny Klient', hourly_rate = 99.00 WHERE id = :c:", ['c' => $s['client']]);
        $this->owner()->query("UPDATE entity_versions SET trade_name = 'Zmenena Firma s.r.o.' WHERE entity_id = :e:", ['e' => $s['entity']]);
        $this->owner()->query('UPDATE tasks SET rate = 99.00 WHERE client_id = :c:', ['c' => $s['client']]);

        $inv = new CapturingPdfRenderer('inv-2');
        $rep = new CapturingPdfRenderer('rep-2');
        $reg = $this->regenerator(['inv-2' => $inv, 'rep-2' => $rep]);
        $reg->regenerate($id, 'invoice', 1);
        $reg->regenerate($id, 'report', 1);

        $snapshot = json_decode((string) $before['snapshot'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($snapshot, $inv->lastSnapshot);
        $this->assertSame($snapshot, $rep->lastSnapshot);
        $this->assertSame($issuedInv, $inv->lastSnapshot);
        $this->assertSame($issuedRep, $rep->lastSnapshot);
        $this->assertNotSame('Iny Klient', $inv->lastSnapshot['client']['name']);
        $this->assertNotSame('Zmenena Firma s.r.o.', $inv->lastSnapshot['entity']['trade_name']);

        // sumy a riadky
        $this->assertSame((string) $before['total'], (string) $inv->lastSnapshot['totals']['total']);
        $lines = $inv->lastSnapshot['lines'] ?? $inv->lastSnapshot['items'] ?? null;
        $this->assertNotNull($lines);
        $this->assertCount(2, $lines);

        // invoices ostava nezmenene
        $this->assertSame($before, $this->row($id));
    }
}
