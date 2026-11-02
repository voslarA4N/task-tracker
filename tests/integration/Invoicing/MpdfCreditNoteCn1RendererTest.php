<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Invoicing\CreditNoteSnapshotBuilder;
use App\Libraries\Invoicing\MpdfCreditNoteCn1Renderer;
use App\Libraries\Invoicing\PdfFormat;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeRateHttpClient;
use Tests\Support\FxFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PdfText;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class MpdfCreditNoteCn1RendererTest extends PgTestCase
{
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-30T10:00:00+01:00'));
        Services::injectMock('rateHttpClient', new FakeRateHttpClient());
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        $this->restoreFxSettings();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $lang = 'sk', string $reason = 'Chybná sadzba', bool $fx = false): array
    {
        if ($fx) {
            $s = $this->fxPlEurSetup();
            $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');
            $i = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-30']));
        } else {
            $s = $this->issueSetup(['parts' => [['8.00', '2026-10'], ['2.50', '2026-10']]]);
            $i = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
        }

        $row   = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $i->id])->getRowArray();
        $lines = $this->owner()->query('SELECT * FROM invoice_lines WHERE invoice_id = :i: ORDER BY position', ['i' => $i->id])->getResultArray();

        return (new CreditNoteSnapshotBuilder())->build(['doc_language' => $lang] + $row, $lines, '2026-10-30', $reason, CorrectionType::AmountError, 'DB-202610001');
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function text(array $snapshot): string
    {
        $pdf  = (new MpdfCreditNoteCn1Renderer())->render($snapshot);
        $text = PdfText::extract($pdf->bytes);

        if ($text === null) {
            $this->markTestSkipped('pdftotext is not available');
        }

        return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text);
    }

    public function testRendersPdfWithHash(): void
    {
        $pdf = (new MpdfCreditNoteCn1Renderer())->render($this->snapshot());

        $this->assertStringStartsWith('%PDF-', $pdf->bytes);
        $this->assertSame('cn-1', $pdf->templateVersion);
        $this->assertSame(hash('sha256', $pdf->bytes), $pdf->sha256);
    }

    public function testContentSlovak(): void
    {
        $snap = $this->snapshot();
        $text = $this->text($snap);
        $c    = $snap['correction'];
        $norm = static fn (string $v): string => (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $v);

        $this->assertStringContainsString('Dobropis DB-202610001', $text);
        $this->assertStringContainsString('Oprava k faktúre č.', $text);
        $this->assertStringContainsString((string) $c['original_number'], $text);
        $this->assertStringContainsString($norm(PdfFormat::date($c['original_issue_date'], 'sk')), $text);
        $this->assertStringContainsString('Chybná suma, hodiny alebo sadzba', $text);
        $this->assertStringContainsString('Chybná sadzba', $text);
        $this->assertStringContainsString('Celé storno pôvodnej faktúry', $text);
        $this->assertStringContainsString($norm(PdfFormat::amount($snap['totals']['total'], $snap['currency'], 'sk')), $text);
        $this->assertStringContainsString('-', $snap['totals']['total']);
        $this->assertStringNotContainsString((string) $snap['labels']['qrCaption'], $text);
        $this->assertStringNotContainsString((string) $snap['bank']['iban'], $text);
    }

    public function testTitlesByLanguage(): void
    {
        foreach (['pl' => 'Faktura korygująca', 'cs' => 'Opravný daňový doklad', 'en' => 'Credit note'] as $lang => $title) {
            $this->assertStringContainsString($title, $this->text($this->snapshot($lang)), $lang);
        }
    }

    public function testForeignCurrencyPrintsFx(): void
    {
        $snap = $this->snapshot('sk', 'Chybný kurz', true);
        $text = $this->text($snap);

        $this->assertNotNull($snap['fx']);
        $this->assertStringContainsString((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $snap['fx']['text']['rate_line']), $text);
        $this->assertStringContainsString((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $snap['fx']['text']['total_value']), $text);
        $this->assertStringContainsString('-', $snap['fx']['text']['total_value']);
    }

    public function testReasonIsEscaped(): void
    {
        $text = $this->text($this->snapshot('sk', '<b>x</b> & y'));

        $this->assertStringContainsString('<b>x</b> & y', $text);
    }

    public function testNullNumberRejected(): void
    {
        $snap           = $this->snapshot();
        $snap['number'] = null;

        $this->expectException(InvalidArgumentException::class);
        (new MpdfCreditNoteCn1Renderer())->render($snap);
    }

    public function testMissingCorrectionRejected(): void
    {
        $snap = $this->snapshot();
        unset($snap['correction']);

        $this->expectException(InvalidArgumentException::class);
        (new MpdfCreditNoteCn1Renderer())->render($snap);
    }
}
