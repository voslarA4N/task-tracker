<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\MpdfInvoiceInv2Renderer;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * D-10 / INV-12: forma uhrady a poznamka od vstupu po stlpce, snapshot a PDF.
 *
 * @internal
 */
#[Group('integration')]
final class PaymentOptionsTest extends PgTestCase
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
        $this->owner()->query("DELETE FROM legal_texts WHERE key = 'invoice_note'");
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    #[DataProvider('provideMethodAndNoteStoredInColumnsAndSnapshot')]
    public function testMethodAndNoteStoredInColumnsAndSnapshot(string $method): void
    {
        $s      = $this->issueSetup();
        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['paymentMethod' => $method, 'paymentNote' => 'Poznamka k platbe']));

        $row = $this->owner()->query("SELECT payment_method, payment_note, snapshot->'payment'->>'method' AS sm, snapshot->'payment'->>'note' AS sn FROM invoices WHERE id = :i:", ['i' => $issued->id])->getRowArray();
        $this->assertSame($method, $row['payment_method']);
        $this->assertSame('Poznamka k platbe', $row['payment_note']);
        $this->assertSame($method, $row['sm']);
        $this->assertSame('Poznamka k platbe', $row['sn']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideMethodAndNoteStoredInColumnsAndSnapshot(): iterable
    {
        return ['transfer' => ['transfer'], 'cash' => ['cash'], 'card' => ['card'], 'other' => ['other']];
    }

    public function testDefaultIsTransferWithoutNote(): void
    {
        $s      = $this->issueSetup();
        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        $row = $this->owner()->query('SELECT payment_method, payment_note FROM invoices WHERE id = :i:', ['i' => $issued->id])->getRowArray();
        $this->assertSame('transfer', $row['payment_method']);
        $this->assertNull($row['payment_note']);
    }

    public function testRealPdfContainsMethodAndNoteButNoIbanForCard(): void
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext is not available');
        }

        $s      = $this->issueSetup();
        $issued = $this->issuer(new MpdfInvoiceInv2Renderer())->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['paymentMethod' => 'card', 'paymentNote' => 'Zaplatene kartou <img src=http://x>']));

        $doc = (new DocumentStore($this->rw()))->read($issued->id);
        $this->assertNotNull($doc);

        $file = tempnam(sys_get_temp_dir(), 'inv');
        file_put_contents($file, $doc->bytes);
        $text = str_replace(["\u{00A0}", "\u{202F}"], ' ', (string) shell_exec('pdftotext ' . escapeshellarg($file) . ' -'));
        unlink($file);

        $this->assertStringContainsString('Karta', $text);
        $this->assertStringContainsString('Zaplatene kartou <img src=http://x>', $text);
        $this->assertStringNotContainsString('SK3112000000198742637541', str_replace(' ', '', $text));
    }
}
