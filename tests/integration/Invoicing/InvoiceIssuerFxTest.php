<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
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
final class InvoiceIssuerFxTest extends PgTestCase
{
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private FakeRateHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-30T10:00:00+01:00'));
        $this->http = new FakeRateHttpClient();
        Services::injectMock('rateHttpClient', $this->http);
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
     * @param array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int} $s
     *
     * @return array{0: int, 1: string, 2: int, 3: list<int>, 4: array<string, mixed>}
     */
    private function issueArgs(array $s): array
    {
        return [$s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-30']];
    }

    private function written(): int
    {
        return (int) $this->owner()->query('SELECT (SELECT count(*) FROM invoices) + (SELECT count(*) FROM invoice_counters) AS c')->getRow()->c;
    }

    public function testIssuesForeignCurrencyWithCachedRateAndNoNetwork(): void
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');

        $issued = $this->issuer()->issue($this->issueCommand(...$this->issueArgs($s)));

        $row = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $issued->id])->getRowArray();
        $this->assertSame('4.40900000', $row['fx_rate']);
        $this->assertSame('NBP', $row['fx_source']);
        $this->assertSame('2026-10-27', $row['fx_date']);
        $this->assertSame('209/A/NBP/2026', $row['fx_table_no']);
        $this->assertSame('delivery_date', $row['fx_date_basis']);
        $this->assertSame('1080.00', $row['total']);
        $this->assertSame('4761.72', $row['total_entity_ccy']);
        $this->assertSame('EUR', trim($row['currency']));

        $snap = json_decode((string) $row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('4761.72', $snap['fx']['total']);
        $this->assertSame('EUR', $snap['bank']['currency']);
        $this->assertSame([], $this->http->calls);

        $audit = json_decode((string) $this->owner()->query("SELECT after_data FROM audit_log WHERE entity_type = 'invoice' AND entity_id = :i: AND action = 'issue'", ['i' => $issued->id])->getRow()->after_data, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('NBP', $audit['fx_source']);
        $this->assertSame('4.4090', $audit['fx_rate']);
        $this->assertFalse($audit['fx_manual']);
        $this->assertSame('4761.72', $audit['total_entity_ccy']);
    }

    public function testMissingRateIsRejectedAndNothingWritten(): void
    {
        $s   = $this->fxPlEurSetup();
        $cmd = $this->issueCommand(...$this->issueArgs($s));

        try {
            $this->issuer()->issue($cmd);
            $this->fail('Expected IssueRejected.');
        } catch (IssueRejected $e) {
            $this->assertSame('fx_missing', $e->reason);
            $this->assertSame('EUR', $e->context['currency']);
            $this->assertSame('PLN', $e->context['entity_currency']);
        }

        $this->assertSame(0, $this->written());
        $this->assertSame([], $this->http->calls);
    }

    public function testRateChangeBetweenPreviewAndIssueIsStale(): void
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');
        $cmd = $this->issueCommand(...$this->issueArgs($s));
        $this->owner()->query("UPDATE exchange_rate_cache SET rate_published = '4.5000'");

        try {
            $this->issuer()->issue($cmd);
            $this->fail('Expected IssueRejected.');
        } catch (IssueRejected $e) {
            $this->assertSame('preview_stale', $e->reason);
        }
        $this->assertSame(0, $this->written());
    }

    public function testNoBankAccountInInvoiceCurrencyIsRejected(): void
    {
        $s = $this->fxPlEurSetup();
        $this->owner()->query("DELETE FROM entity_bank_accounts WHERE entity_id = :e: AND currency = 'EUR'", ['e' => $s['entity']]);
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');

        try {
            $this->issuer()->issue($this->issueCommand(...$this->issueArgs($s)));
            $this->fail('Expected IssueRejected.');
        } catch (IssueRejected $e) {
            $this->assertSame('no_bank_account', $e->reason);
        }
    }

    public function testSameCurrencyHasNoFx(): void
    {
        $s      = $this->issueSetup();
        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        $row = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $issued->id])->getRowArray();
        $this->assertNull($row['fx_rate']);
        $this->assertNull($row['fx_source']);
        $this->assertSame($row['total'], $row['total_entity_ccy']);
        $this->assertNull(json_decode((string) $row['snapshot'], true, 512, JSON_THROW_ON_ERROR)['fx']);
    }

    public function testDbChecksRejectInconsistentFxColumns(): void
    {
        $db = $this->owner();
        $db->query('CREATE TEMP TABLE fx_probe (fx_rate numeric, fx_source text, fx_date date, total numeric, total_entity_ccy numeric)');

        try {
            // CHECK z katalogu invoices sa aplikuje na docasnu tabulku s rovnakymi stlpcami
            foreach (['invoices_fx_pair_check', 'invoices_fx_total_check'] as $name) {
                $def = (string) $db->query('SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conrelid = \'invoices\'::regclass AND conname = :n:', ['n' => $name])->getRow()->d;
                $db->query('ALTER TABLE fx_probe ADD CONSTRAINT ' . $name . ' ' . $def);
            }

            $db->query("INSERT INTO fx_probe VALUES (NULL, NULL, NULL, 10, 10), (4.5, 'NBP', '2026-10-27', 10, 45)");

            foreach ([
                "INSERT INTO fx_probe VALUES (4.5, NULL, '2026-10-27', 10, 45)" => 'invoices_fx_pair_check',
                'INSERT INTO fx_probe VALUES (NULL, NULL, NULL, 10, 11)'        => 'invoices_fx_total_check',
            ] as $sql => $constraint) {
                try {
                    $db->query($sql);
                    $this->fail('Expected check violation ' . $constraint);
                } catch (DatabaseException $e) {
                    $this->assertStringContainsString($constraint, $e->getMessage());
                }
            }
        } finally {
            $db->query('DROP TABLE IF EXISTS fx_probe');
        }
    }
}
