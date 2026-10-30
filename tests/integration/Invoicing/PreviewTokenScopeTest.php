<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\InvoiceCalculator;
use App\Libraries\Invoicing\InvoiceParts;
use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Invoicing\Samples\SampleSnapshots;
use App\Libraries\Invoicing\SnapshotBuilder;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FxFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * Token nahladu pokryva celu kopiu udajov a poradie riadkov (quick 261027-jjx, WR-01 a WR-04).
 *
 * @internal
 */
#[Group('integration')]
final class PreviewTokenScopeTest extends PgTestCase
{
    use FxFixtures;
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

    private function rows(string $table): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM {$table}")->getRow()->c;
    }

    /**
     * @param array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int} $s
     * @param array<string, mixed>                                                             $overrides
     */
    private function assertStale(array $s, string $token, array $overrides = []): void
    {
        $counters = $this->rows('invoice_counters');

        try {
            $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['previewToken' => $token] + $overrides));
            $this->fail('Expected IssueRejected');
        } catch (IssueRejected $e) {
            $this->assertSame('preview_stale', $e->reason);
        }

        $this->assertSame(0, $this->rows('invoices'));
        $this->assertSame($counters, $this->rows('invoice_counters'));
    }

    public function testUnchangedPreviewIssues(): void
    {
        $s      = $this->issueSetup();
        $token  = $this->previewTokenFor($s['client'], '2026-10', $s['entity'], $s['parts']);
        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['previewToken' => $token]));

        $this->assertNotSame('', $issued->number);
        $this->assertSame(1, $this->rows('invoices'));
    }

    public function testTokenIgnoresNumberAndQr(): void
    {
        $snap                  = SampleSnapshots::invoice('sk', null, ['number' => null, 'variable_symbol' => null]);
        $with                  = (new SnapshotBuilder($this->rw()))->withNumber($snap, '202610001');
        $with['payment']['qr'] = ['payload' => 'x'];

        $this->assertSame(InvoiceCalculator::previewToken($snap), InvoiceCalculator::previewToken($with));
    }

    public function testRateChangeChangesToken(): void
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026', '2026-10-27 09:59:00+01');
        $token = $this->previewTokenFor($s['client'], '2026-10', $s['entity'], $s['parts']);
        $this->owner()->query("UPDATE exchange_rate_cache SET rate_published = '4.5000', rate = 4.5");
        $this->assertNotSame($token, $this->previewTokenFor($s['client'], '2026-10', $s['entity'], $s['parts']));
    }

    public function testIssueDateChangeIsStale(): void
    {
        $s     = $this->issueSetup();
        $token = $this->previewTokenFor($s['client'], '2026-10', $s['entity'], $s['parts']);
        $this->assertStale($s, $token, ['issueDate' => '2026-10-28']);
    }

    public function testBankAccountChangeIsStale(): void
    {
        $s     = $this->issueSetup();
        $token = $this->previewTokenFor($s['client'], '2026-10', $s['entity'], $s['parts']);
        $this->owner()->query("UPDATE entity_bank_accounts SET iban = 'SK0211000000002612345678' WHERE entity_id = :e:", ['e' => $s['entity']]);
        $this->assertStale($s, $token);
    }

    public function testEntityVersionAddressChangeIsStale(): void
    {
        $s     = $this->issueSetup();
        $token = $this->previewTokenFor($s['client'], '2026-10', $s['entity'], $s['parts']);
        // UPDATE cez owner spojenie (bez immutability triggera pre vlastnika), mení verziu platnu k datumu vystavenia
        $this->owner()->query("UPDATE entity_versions SET address_line1 = 'Ina ulica 99' WHERE entity_id = :e:", ['e' => $s['entity']]);
        $this->assertStale($s, $token);
    }

    public function testClientAddressChangeIsStale(): void
    {
        $s     = $this->issueSetup();
        $token = $this->previewTokenFor($s['client'], '2026-10', $s['entity'], $s['parts']);
        $this->owner()->query("UPDATE clients SET address_line1 = 'Ina ulica 99' WHERE id = :c:", ['c' => $s['client']]);
        $this->assertStale($s, $token);
    }

    public function testLineOrderAcrossDstFallBackMatchesPreview(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['20.00', '2026-10']]]);
        // prva (nizsie id) ulohy je ako okamih skorsia, ale ako text v Europe/Bratislava neskorsia;
        // stary PHP usort nad textom created_at by poradie prehodil
        $this->owner()->query("UPDATE tasks SET created_at = '2026-10-25 02:30:00+02' WHERE id = :t:", ['t' => $s['tasks'][0]]);
        $this->owner()->query("UPDATE tasks SET created_at = '2026-10-25 02:10:00+01' WHERE id = :t:", ['t' => $s['tasks'][1]]);

        $preview = array_map(
            static fn (array $p): int => (int) $p['allocation_id'],
            (new InvoiceParts($this->rw()))->unlocked($s['client'], '2026-10', null),
        );
        $this->assertSame([(int) $s['parts'][0], (int) $s['parts'][1]], $preview);

        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
        $this->assertNotSame('', $issued->number);

        $rows  = $this->owner()->query('SELECT source_allocation_id FROM invoice_lines ORDER BY position')->getResultArray();
        $order = array_map(static fn (array $r): int => (int) $r['source_allocation_id'], $rows);
        $this->assertSame($preview, $order);
    }

    public function testPaymentIsIgnoredByTokenButInvoiceNoteChangeIsStale(): void
    {
        $s      = $this->issueSetup();
        $token  = $this->previewTokenFor($s['client'], '2026-10', $s['entity'], $s['parts']);
        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['previewToken' => $token, 'paymentMethod' => 'cash', 'paymentNote' => 'Ina poznamka']));
        $this->assertNotSame('', $issued->number);
    }

    public function testInvoiceNoteWordingChangeIsStale(): void
    {
        $s = $this->issueSetup();
        $this->owner()->query("INSERT INTO legal_texts (country, vat_regime, billing_mode, language, key, body, provisional, updated_at) VALUES ('SK', 'sk_non_vat', NULL, 'sk', 'invoice_note', 'Povodne', false, now())");

        try {
            $token = $this->previewTokenFor($s['client'], '2026-10', $s['entity'], $s['parts']);
            $this->owner()->query("UPDATE legal_texts SET body = 'Zmenene' WHERE key = 'invoice_note'");
            $this->assertStale($s, $token);
        } finally {
            $this->owner()->query("DELETE FROM legal_texts WHERE key = 'invoice_note'");
        }
    }

    public function testRealQrDoesNotChangeToken(): void
    {
        $s      = $this->issueSetup();
        $token  = $this->previewTokenFor($s['client'], '2026-10', $s['entity'], $s['parts']);
        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['previewToken' => $token]));

        $qr = $this->owner()->query("SELECT snapshot->'payment'->'qr'->>'standard' AS st FROM invoices WHERE number = :n:", ['n' => $issued->number])->getRow();
        $this->assertSame('pay_by_square', $qr->st);
    }
}
