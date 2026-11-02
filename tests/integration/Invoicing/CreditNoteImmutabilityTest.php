<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Master\ClientService;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\FxFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * Po dobropise ostava povodna faktura aj dobropis nemenny (D-06, D-16, Core Value).
 *
 * @internal
 */
#[Group('integration')]
final class CreditNoteImmutabilityTest extends PgTestCase
{
    use CreditNoteFixtures;
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

    /**
     * @return array<string, mixed>
     */
    private function state(int $invoiceId): array
    {
        $invoice = $this->owner()->query('SELECT * FROM invoices WHERE id = :id:', ['id' => $invoiceId])->getRowArray();
        $lines   = $this->owner()->query('SELECT * FROM invoice_lines WHERE invoice_id = :id: ORDER BY id', ['id' => $invoiceId])->getResultArray();
        $docs    = [];
        $store   = new DocumentStore($this->rw());

        foreach (['invoice', 'report'] as $kind) {
            $d           = $store->read($invoiceId, $kind);
            $docs[$kind] = $d === null ? null : ['bytes' => $d->bytes, 'sha256' => $d->sha256];
        }

        return ['invoice' => $invoice, 'snapshot' => json_decode((string) $invoice['snapshot'], true, 512, JSON_THROW_ON_ERROR), 'lines' => $lines, 'docs' => $docs];
    }

    /**
     * @return array{0: array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int}, 1: int}
     */
    private function issued(): array
    {
        $s  = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.50', '2026-10']]]);
        $id = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
        Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));

        return [$s, $id];
    }

    public function testOriginalInvoiceIsUnchangedByCreditNote(): void
    {
        [, $f1] = $this->issued();
        $before = $this->state($f1);
        $this->assertNotNull($before['docs']['invoice']);
        $this->assertNotNull($before['docs']['report']);

        $this->issueCreditNote($f1);

        $after = $this->state($f1);
        $this->assertSame($before, $after);
        $this->assertSame(hash('sha256', $after['docs']['invoice']['bytes']), $after['docs']['invoice']['sha256']);
        $this->assertSame(hash('sha256', $after['docs']['report']['bytes']), $after['docs']['report']['sha256']);
    }

    public function testInvoiceAndCreditNoteUpdateDeleteBlocked(): void
    {
        [, $f1] = $this->issued();
        $cn     = $this->issueCreditNote($f1)->id;

        $this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE invoices SET total = 0 WHERE id = ' . $f1));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM invoices WHERE id = ' . $cn));

        foreach (['UPDATE invoices SET total = 0 WHERE id = ' . $f1, 'UPDATE invoices SET total = 0 WHERE id = ' . $cn, 'DELETE FROM invoices WHERE id = ' . $cn] as $sql) {
            try {
                $this->owner()->query($sql);
                $this->fail('Expected immutable error: ' . $sql);
            } catch (DatabaseException $e) {
                $this->assertMatchesRegularExpression('/immutable/', $e->getMessage());
            }
        }
    }

    public function testCreditNoteSurvivesLaterChanges(): void
    {
        [$s, $f1] = $this->issued();
        $cn       = $this->issueCreditNote($f1)->id;
        $before   = $this->state($cn);
        $this->assertNotNull($before['docs']['invoice']);

        $audit   = new AuditLogger($this->rw());
        $clients = new ClientService($this->rw(), $audit);
        $client  = $clients->find($s['client']);
        $this->assertNotNull($client);
        $clients->update($s['client'], ['name' => 'Iny Klient', 'invoice_email' => 'iny@example.com', 'hourly_rate' => '99.00'] + $client);

        $this->owner()->query("UPDATE entity_versions SET trade_name = 'Zmenena Firma s.r.o.' WHERE entity_id = :e:", ['e' => $s['entity']]);
        $this->owner()->query("UPDATE clients SET currency = 'PLN' WHERE id = :id:", ['id' => $s['client']]);
        $this->owner()->query('UPDATE tasks SET rate = 99.00, title = :t: WHERE client_id = :id:', ['t' => 'Prepisany nazov', 'id' => $s['client']]);

        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-30', '4.2000', 1, '1/A/NBP/2026');
        $this->owner()->query("UPDATE exchange_rate_cache SET rate = 9.9999, rate_published = 9.9999 WHERE source = 'NBP'");

        $after = $this->state($cn);
        $this->assertSame($before, $after);
        $this->assertSame(hash('sha256', $after['docs']['invoice']['bytes']), $after['docs']['invoice']['sha256']);
        $this->assertSame($before['invoice']['total'], $after['invoice']['total']);
        $this->assertSame($before['invoice']['total_entity_ccy'], $after['invoice']['total_entity_ccy']);
    }
}
