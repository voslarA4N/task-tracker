<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Invoicing\CreditNoteRejected;
use App\Libraries\Invoicing\CreditNoteSnapshotBuilder;
use App\Libraries\Invoicing\PdfFormat;
use App\Libraries\Invoicing\SnapshotBuilder;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Money;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClassConstant;
use Tests\Support\FakeRateHttpClient;
use Tests\Support\FxFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class CreditNoteSnapshotBuilderTest extends PgTestCase
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
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: array<string, mixed>}
     */
    private function issued(): array
    {
        $s = $this->issueSetup(['parts' => [['8.00', '2026-10'], ['2.50', '2026-10']]]);
        $i = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        return $this->load($i->id) + [2 => $s];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function load(int $id): array
    {
        $row   = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $id])->getRowArray();
        $lines = $this->owner()->query('SELECT * FROM invoice_lines WHERE invoice_id = :i: ORDER BY position', ['i' => $id])->getResultArray();

        return [$row, $lines];
    }

    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function build(array $row, array $lines, ?string $number = null, string $reason = 'Chybná sadzba', CorrectionType $type = CorrectionType::AmountError, string $date = '2026-10-30'): array
    {
        return (new CreditNoteSnapshotBuilder())->build($row, $lines, $date, $reason, $type, $number);
    }

    public function testShapeAndNegativeLines(): void
    {
        [$row, $lines] = $this->issued();
        $snap          = $this->build($row, $lines);
        $orig          = json_decode((string) $row['snapshot'], true);

        $this->assertSame(4, $snap['schema']);
        $this->assertSame('credit_note', $snap['type']);
        $this->assertSame('cn-1', $snap['template_version']);
        $this->assertArrayNotHasKey('report', $snap);
        $this->assertArrayNotHasKey('report_template_version', $snap);
        $this->assertArrayNotHasKey('report', $snap['labels']);

        $this->assertCount(count($lines), $snap['lines']);

        foreach ($snap['lines'] as $i => $l) {
            $o = $lines[$i];
            $this->assertSame('-' . $o['hours'], $l['hours']);
            $this->assertSame($o['rate'], $l['rate']);
            $this->assertSame('-' . $o['amount'], $l['amount']);
            $this->assertNull($l['source_allocation_id']);
            $this->assertSame($o['source_task_id'], $l['source_task_id']);
            $this->assertSame($o['vat_code'], $l['vat_code']);
            $this->assertTrue(Money::of($l['rate'])->timesHours($l['hours'])->equals(Money::of($l['amount'])));
        }

        $this->assertSame('-' . $row['total'], $snap['totals']['total']);
        $this->assertSame('-10.50', $snap['totals']['hours']);
        $this->assertSame(count($lines), $snap['totals']['line_count']);
        $this->assertTrue(Money::of($snap['totals']['total'])->plus(Money::of((string) $row['total']))->isZero());

        foreach (['entity', 'client', 'bank', 'legal'] as $k) {
            $this->assertSame($orig[$k], $snap[$k]);
        }

        $this->assertNull($snap['payment']['qr']);
        $this->assertSame('2026-10-30', $snap['due_date']);
        $this->assertSame($orig['delivery_date'], $snap['delivery_date']);
        $this->assertSame($orig['billing_month'], $snap['billing_month']);
        $this->assertSame($orig['currency'], $snap['currency']);
        $this->assertSame($orig['doc_language'], $snap['doc_language']);

        $this->assertSame([
            'original_id'         => (int) $row['id'],
            'original_number'     => $row['number'],
            'original_issue_date' => $orig['issue_date'],
            'reason'              => 'Chybná sadzba',
            'type'                => 'amount_error',
            'scope'               => 'full',
        ], $snap['correction']);
    }

    public function testNumberAndPreviewToken(): void
    {
        [$row, $lines] = $this->issued();
        $without       = $this->build($row, $lines);
        $with          = $this->build($row, $lines, 'DB-202610001');

        $this->assertSame('DB-202610001', $with['number']);
        $this->assertSame(SnapshotBuilder::variableSymbol('DB-202610001'), $with['variable_symbol']);
        $this->assertSame(CreditNoteSnapshotBuilder::previewToken($without), CreditNoteSnapshotBuilder::previewToken($with));
        $this->assertSame(CreditNoteSnapshotBuilder::previewToken($without), CreditNoteSnapshotBuilder::previewToken($this->build($row, $lines)));

        $base = CreditNoteSnapshotBuilder::previewToken($without);
        $this->assertNotSame($base, CreditNoteSnapshotBuilder::previewToken($this->build($row, $lines, null, 'Iný dôvod')));
        $this->assertNotSame($base, CreditNoteSnapshotBuilder::previewToken($this->build($row, $lines, null, 'Chybná sadzba', CorrectionType::Other)));
        $this->assertNotSame($base, CreditNoteSnapshotBuilder::previewToken($this->build($row, $lines, null, 'Chybná sadzba', CorrectionType::AmountError, '2026-10-29')));
    }

    public function testForeignCurrencyCopiesFxWithNegatedTotal(): void
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');
        $i = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-30']));

        [$row, $lines] = $this->load($i->id);
        $orig          = json_decode((string) $row['snapshot'], true);
        $snap          = $this->build($row, $lines);

        $this->assertNotNull($snap['fx']);

        foreach (['rate', 'source', 'valid_for', 'table_no'] as $k) {
            $this->assertSame($orig['fx'][$k], $snap['fx'][$k]);
        }

        $this->assertSame($orig['fx']['text']['rate_line'], $snap['fx']['text']['rate_line']);
        $this->assertSame($orig['fx']['text']['date_line'], $snap['fx']['text']['date_line']);
        $this->assertSame('-' . $orig['fx']['total'], $snap['fx']['total']);
        $this->assertSame(PdfFormat::amount('-' . $orig['fx']['total'], $orig['fx']['entity_currency'], $orig['doc_language']), $snap['fx']['text']['total_value']);
        $this->assertTrue(Money::of($snap['fx']['total'])->plus(Money::of($orig['fx']['total']))->isZero());
    }

    public function testDoesNotReadLiveTables(): void
    {
        [$row, $lines, $s] = $this->issued();
        $before            = $this->build($row, $lines);

        $this->owner()->query("UPDATE clients SET name = 'Zmenene s.r.o.' WHERE id = :c:", ['c' => $s['client']]);
        $this->owner()->query("UPDATE entity_versions SET trade_name = 'Zmenene' WHERE entity_id = :e:", ['e' => $s['entity']]);

        $this->assertSame($before, $this->build($row, $lines));
    }

    public function testTitleByLanguage(): void
    {
        [$row, $lines] = $this->issued();

        foreach (['sk' => 'Dobropis', 'pl' => 'Faktura korygująca', 'cs' => 'Opravný daňový doklad', 'en' => 'Credit note'] as $lang => $title) {
            $snap = $this->build(['doc_language' => $lang] + $row, $lines);
            $this->assertSame($title, $snap['labels']['cnTitle'], $lang);
            $this->assertSame($lang, $snap['labels']['language']);
            $this->assertArrayHasKey('invoice', $snap['labels']);
        }
    }

    public function testMismatchesAreRejected(): void
    {
        [$row, $lines] = $this->issued();

        try {
            $this->build($row, array_slice($lines, 0, 1));
            $this->fail('expected parts_mismatch');
        } catch (CreditNoteRejected $e) {
            $this->assertSame('parts_mismatch', $e->reason);
        }

        $tampered              = $lines;
        $tampered[0]['amount'] = '1.00';

        try {
            $this->build($row, $tampered);
            $this->fail('expected parts_mismatch');
        } catch (CreditNoteRejected $e) {
            $this->assertSame('parts_mismatch', $e->reason);
        }

        try {
            $this->build(['total' => '1.00'] + $row, $lines);
            $this->fail('expected sum_mismatch');
        } catch (CreditNoteRejected $e) {
            $this->assertSame('sum_mismatch', $e->reason);
        }
    }

    public function testInvoiceLabelKeysUnchanged(): void
    {
        $keys = (new ReflectionClassConstant(SnapshotBuilder::class, 'LABEL_KEYS'))->getValue();
        $this->assertIsArray($keys);
        $this->assertCount(43, $keys);
    }
}
