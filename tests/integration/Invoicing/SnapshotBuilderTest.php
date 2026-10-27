<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\InvoiceParts;
use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Invoicing\LegalProfile;
use App\Libraries\Invoicing\Qr\PayBySquare;
use App\Libraries\Invoicing\ReportRows;
use App\Libraries\Invoicing\Samples\SampleSnapshots;
use App\Libraries\Invoicing\SnapshotBuilder;
use App\Libraries\Master\EntityService;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PayBySquareDecoder;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class SnapshotBuilderTest extends PgTestCase
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

    /**
     * @param array<string, mixed> $setup
     *
     * @return array<string, mixed>
     */
    private function build(array $setup, string $issueDate = '2026-10-27', ?string $number = null): array
    {
        $client = $this->rw()->query('SELECT * FROM clients WHERE id = :id:', ['id' => $setup['client']])->getRowArray();
        $entity = $this->rw()->query('SELECT * FROM entities WHERE id = :id:', ['id' => $setup['entity']])->getRowArray();
        $parts  = (new InvoiceParts($this->rw()))->unlocked($setup['client'], '2026-10', $client['portal_url_template']);

        return (new SnapshotBuilder($this->rw()))->build($client, $entity, $parts, '2026-10', $issueDate, '2026-10-31', '2026-11-10', $number);
    }

    public function testSlovakSnapshotIsSelfContained(): void
    {
        $s    = $this->issueSetup();
        $snap = $this->build($s);

        $this->assertSame('sk', $snap['doc_language']);
        $this->assertSame('sk_non_vat', $snap['legal']['vat_regime']);
        $this->assertSame('np', $snap['legal']['vat_code']);
        $this->assertSame('Nie som platiteľ DPH.', $snap['legal']['texts']['vat_note']['body']);
        $this->assertSame('SK3112000000198742637541', $snap['bank']['iban']);
        $this->assertSame('Faktúra', $snap['labels']['invoice']);
        $this->assertNull($snap['number']);
        $this->assertSame('np', $snap['lines'][0]['vat_code']);
        $this->assertSame('1080.00', $snap['totals']['total']);
        $this->assertSame('EUR', $snap['currency']);
    }

    public function testSampleSnapshotLegalTextHasResolverShape(): void
    {
        $snap   = $this->build($this->issueSetup());
        $sample = SampleSnapshots::invoice('sk');

        $this->assertSame(array_keys($snap['legal']['texts']['vat_note']), array_keys($sample['legal']['texts']['vat_note']));
        $this->assertSame(1, $sample['legal']['texts']['vat_note']['revision']);
    }

    public function testSnapshotIsSchemaTwoWithPrintRules(): void
    {
        $snap = $this->build($this->issueSetup());

        $this->assertSame(2, $snap['schema']);
        $this->assertSame('inv-2', $snap['template_version']);
        $this->assertSame(LegalProfile::print('SK', 'sk_non_vat', 'domestic'), $snap['legal']['print']);
        $this->assertSame('vat_note', $snap['legal']['texts']['vat_note']['key']);
        $this->assertSame(['method' => 'transfer', 'note' => null, 'qr' => null], $snap['payment']);

        foreach (SnapshotBuilder::LABEL_KEYS as $key) {
            $this->assertArrayHasKey($key, $snap['labels']);
            $this->assertNotSame('', $snap['labels'][$key]);
        }
    }

    public function testSnapshotCarriesReportContent(): void
    {
        $snap = $this->build($this->issueSetup());

        $this->assertSame('rep-1', $snap['report_template_version']);
        $this->assertSame('október 2026', $snap['report']['period']);
        $this->assertCount(count($snap['lines']), $snap['report']['rows']);
        $this->assertSame('Výkaz práce', $snap['labels']['report']['title']);

        foreach (ReportRows::LABEL_KEYS as $key) {
            $this->assertNotSame('', $snap['labels']['report'][$key]);
        }

        $pl = $this->build($this->issueSetup(['client' => ['doc_language' => 'pl']]));
        $this->assertSame('październik 2026', $pl['report']['period']);
    }

    public function testClientLanguageOverridesEntityLanguage(): void
    {
        $s    = $this->issueSetup(['client' => ['doc_language' => 'en']]);
        $snap = $this->build($s);

        $this->assertSame('Invoice', $snap['labels']['invoice']);
        $this->assertSame('Not a VAT payer.', $snap['legal']['texts']['vat_note']['body']);
    }

    public function testPolishExemptEntity(): void
    {
        $s    = $this->issueSetup(['country' => 'PL']);
        $snap = $this->build($s);

        $this->assertSame('zw', $snap['legal']['vat_code']);
        $this->assertSame('ART_113_1', $snap['legal']['exemption_code']);
        $this->assertSame('PLN', $snap['currency']);
    }

    public function testEntityVersionIsResolvedAtIssueDate(): void
    {
        $s       = $this->issueSetup();
        $first   = (int) $this->owner()->query('SELECT id FROM entity_versions WHERE entity_id = :e:', ['e' => $s['entity']])->getRow()->id;
        $current = (array) $this->owner()->query('SELECT * FROM entity_versions WHERE id = :id:', ['id' => $first])->getRowArray();
        unset($current['id'], $current['entity_id'], $current['valid_from'], $current['created_at'], $current['created_by']);
        $current['trade_name'] = 'Nova firma';
        $second                = (new EntityService($this->rw(), new AuditLogger($this->rw())))->newVersion($s['entity'], '2026-11-01', $current);

        $this->assertSame($first, $this->build($s, '2026-10-31')['entity']['version_id']);
        $this->assertSame($second, $this->build($s, '2026-11-01')['entity']['version_id']);
    }

    public function testPortalUrlFromTemplateAndRejectedScheme(): void
    {
        $s = $this->issueSetup(['client' => ['portal_url_template' => 'https://taskman.example/task/{id}'], 'parts' => [['40.00', '2026-10', ['url' => '1234']]]]);
        $this->owner()->query("UPDATE clients SET portal_url_template = 'https://taskman.example/task/{id}' WHERE id = :id:", ['id' => $s['client']]);

        $line = $this->build($s)['lines'][0];
        $this->assertSame('https://taskman.example/task/1234', $line['portal_url']);
        $this->assertSame('1234', $line['task_ref']);

        $this->owner()->query("UPDATE tasks SET url = 'javascript:alert(1)' WHERE id = :id:", ['id' => $s['tasks'][0]]);
        $this->assertNull($this->build($s)['lines'][0]['portal_url']);
    }

    public function testMissingBankAccountForCurrency(): void
    {
        $s = $this->issueSetup();
        $this->owner()->query('DELETE FROM entity_bank_accounts WHERE entity_id = :e:', ['e' => $s['entity']]);

        try {
            $this->build($s);
            $this->fail('Expected IssueRejected');
        } catch (IssueRejected $e) {
            $this->assertSame('no_bank_account', $e->reason);
        }
    }

    public function testMissingLegalTextBlocksIssue(): void
    {
        $s = $this->issueSetup(['country' => 'CZ']);

        $saved = $this->owner()->query("SELECT * FROM legal_texts WHERE country = 'CZ'")->getResultArray();
        $this->assertNotSame([], $saved);
        $this->owner()->query("DELETE FROM legal_texts WHERE country = 'CZ'");

        try {
            try {
                $this->build($s);
                $this->fail('Expected IssueRejected');
            } catch (IssueRejected $e) {
                $this->assertSame('legal_text_missing', $e->reason);
                $this->assertSame('cs', $e->context['language']);
            }
        } finally {
            foreach ($saved as $row) {
                unset($row['id']);
                $this->owner()->table('legal_texts')->insert($row);
            }
        }
    }

    public function testWithNumberSetsVariableSymbol(): void
    {
        $s    = $this->issueSetup();
        $snap = (new SnapshotBuilder($this->rw()))->withNumber($this->build($s), '202610001');

        $this->assertSame('202610001', $snap['number']);
        $this->assertSame('202610001', $snap['variable_symbol']);
    }

    public function testVariableSymbolIsDigitsOnlyAtMostTenDigits(): void
    {
        // WR-05: VS pre SK/CZ banky je ciselny, najviac 10 cislic; format cisla faktury sa nemeni.
        $s    = $this->issueSetup();
        $snap = (new SnapshotBuilder($this->rw()))->withNumber($this->build($s), 'FA-2026/001');

        $this->assertSame('FA-2026/001', $snap['number']);
        $this->assertSame('2026001', $snap['variable_symbol']);
        $this->assertSame('6100012345', SnapshotBuilder::variableSymbol('INV/2026-10/0012345'));
        $this->assertSame('202610001', SnapshotBuilder::variableSymbol('202610001'));
        $this->assertNull(SnapshotBuilder::variableSymbol('FA-X'));
    }

    public function testUnlockedPartsOrderAndHasLocked(): void
    {
        $s     = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['20.00', '2026-10']]]);
        $parts = new InvoiceParts($this->rw());

        $this->owner()->query("UPDATE tasks SET created_at = '2026-01-01 10:00:00+00' WHERE id = :id:", ['id' => $s['tasks'][1]]);

        $rows = $parts->unlocked($s['client'], '2026-10', null);
        $this->assertSame([$s['parts'][1], $s['parts'][0]], array_column($rows, 'allocation_id'));
        $this->assertFalse($parts->hasLocked($s['client'], '2026-10'));

        $this->lockAllocation($s['parts'][0]);
        $this->assertTrue($parts->hasLocked($s['client'], '2026-10'));
        $this->assertCount(1, $parts->unlocked($s['client'], '2026-10', null));
    }

    public function testPaymentAndInvoiceNoteInSnapshot(): void
    {
        $s    = $this->issueSetup();
        $snap = $this->build($s);
        $this->assertSame(['method' => 'transfer', 'note' => null, 'qr' => null], $snap['payment']);
        $this->assertNull($snap['legal']['texts']['invoice_note']);

        $client = $this->rw()->query('SELECT * FROM clients WHERE id = :id:', ['id' => $s['client']])->getRowArray();
        $entity = $this->rw()->query('SELECT * FROM entities WHERE id = :id:', ['id' => $s['entity']])->getRowArray();
        $parts  = (new InvoiceParts($this->rw()))->unlocked($s['client'], '2026-10', $client['portal_url_template']);

        $this->owner()->query("INSERT INTO legal_texts (country, vat_regime, billing_mode, language, key, body, provisional, updated_at) VALUES ('SK', 'sk_non_vat', NULL, 'sk', 'invoice_note', 'Vzor', false, now())");

        try {
            $snap = (new SnapshotBuilder($this->rw()))->build($client, $entity, $parts, '2026-10', '2026-10-27', '2026-10-31', '2026-11-10', null, 'cash', 'Poznamka');
        } finally {
            $this->owner()->query("DELETE FROM legal_texts WHERE key = 'invoice_note'");
        }

        $this->assertSame(['method' => 'cash', 'note' => 'Poznamka', 'qr' => null], $snap['payment']);
        $this->assertSame(['key' => 'invoice_note', 'body' => 'Vzor', 'language' => 'sk', 'provisional' => false, 'revision' => 1], $snap['legal']['texts']['invoice_note']);
    }

    public function testQrOnlyAfterNumberForSkTransfer(): void
    {
        $s        = $this->issueSetup();
        $built    = $this->build($s);
        $numbered = (new SnapshotBuilder($this->rw()))->withNumber($built, '202610001');

        $this->assertNull($built['payment']['qr']);
        $this->assertSame('pay_by_square', $numbered['payment']['qr']['standard']);
        $this->assertMatchesRegularExpression('/^[0-9A-V]+$/', $numbered['payment']['qr']['payload']);

        $decoded = PayBySquareDecoder::decode($numbered['payment']['qr']['payload']);
        $this->assertTrue($decoded['crc_ok']);
        $this->assertContains($numbered['bank']['iban'], $decoded['fields']);
        $this->assertContains((new PayBySquare())->tabFields($numbered)[3], $decoded['fields']);
    }

    public function testNoQrForCash(): void
    {
        $s                         = $this->issueSetup();
        $snap                      = $this->build($s);
        $snap['payment']['method'] = 'cash';

        $this->assertNull((new SnapshotBuilder($this->rw()))->withNumber($snap, '202610001')['payment']['qr']);
    }
}
