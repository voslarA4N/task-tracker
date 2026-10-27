<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\MpdfInvoiceInv2Renderer;
use App\Libraries\Invoicing\MpdfReportRenderer;
use App\Libraries\Master\LegalTextService;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * ROADMAP kriterium 4: nove znenie ovplyvni len doklady vystavene po zmene (D-14).
 *
 * @internal
 */
#[Group('integration')]
final class LegalTextVersioningTest extends PgTestCase
{
    use IssueFixtures;
    use MasterFixtures;

    private int $textId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
        $this->textId = (int) $this->owner()->query("SELECT id FROM legal_texts WHERE country = 'SK' AND language = 'sk' AND key = 'vat_note'")->getRow()->id;
    }

    protected function tearDown(): void
    {
        $this->owner()->query("UPDATE legal_texts SET body = 'Nie som platiteľ DPH.', provisional = true, revision = 1 WHERE id = :id:", ['id' => $this->textId]);
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    public function testEditAffectsOnlyLaterInvoices(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['4.00', '2026-10']]]);

        $a = $this->issuer(new MpdfInvoiceInv2Renderer(), new MpdfReportRenderer())->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]));

        $docs    = new DocumentStore($this->rw());
        $invoice = $docs->read($a->id, 'invoice');
        $report  = $docs->read($a->id, 'report');
        $this->assertNotNull($invoice);
        $this->assertNotNull($report);

        $rev = (new LegalTextService($this->rw(), new AuditLogger($this->rw())))->update($this->textId, 'Dodavatel nie je platitel DPH - nove znenie.', false, 1);
        $this->assertSame(2, $rev);

        $b = $this->issuer(new MpdfInvoiceInv2Renderer(), new MpdfReportRenderer())->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]], ['requestKey' => $this->uuid4()]));

        $snapA = $this->snapshot($a->id);
        $snapB = $this->snapshot($b->id);
        $this->assertSame('Nie som platiteľ DPH.', $snapA['legal']['texts']['vat_note']['body']);
        $this->assertSame(1, $snapA['legal']['texts']['vat_note']['revision']);
        $this->assertSame('Dodavatel nie je platitel DPH - nove znenie.', $snapB['legal']['texts']['vat_note']['body']);
        $this->assertSame(2, $snapB['legal']['texts']['vat_note']['revision']);
        $this->assertFalse($snapB['legal']['texts']['vat_note']['provisional']);

        foreach (['invoice' => $invoice, 'report' => $report] as $kind => $before) {
            $after = $docs->read($a->id, $kind);
            $this->assertNotNull($after);
            $this->assertSame($before->bytes, $after->bytes);
            $this->assertSame($before->sha256, $after->sha256);
        }

        $docB = $docs->read($b->id, 'invoice');
        $this->assertNotNull($docB);

        if (trim((string) shell_exec('command -v pdftotext')) !== '') {
            $path = tempnam(sys_get_temp_dir(), 'inv');
            $this->assertIsString($path);
            file_put_contents($path, $docB->bytes);
            $text = (string) shell_exec('pdftotext ' . escapeshellarg($path) . ' -');
            unlink($path);
            $this->assertStringContainsString('nove znenie', $text);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(int $invoiceId): array
    {
        $raw = (string) $this->owner()->query('SELECT snapshot FROM invoices WHERE id = :id:', ['id' => $invoiceId])->getRow()->snapshot;

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
