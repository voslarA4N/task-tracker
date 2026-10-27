<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\MpdfInvoiceInv2Renderer;
use App\Libraries\Master\ClientService;
use App\Libraries\Master\EntityInput;
use App\Libraries\Master\EntityService;
use App\Libraries\Master\EntityVersionResolver;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * Zmen vsetko po vystaveni a porovnaj bajty (D-23, INV-03, INV-13).
 *
 * @internal
 */
#[Group('integration')]
final class InvoiceImmutableAfterChangeTest extends PgTestCase
{
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

    public function testIssuedInvoiceAndPdfBytesSurviveAllLaterChanges(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.50', '2026-10']]]);

        $issued = $this->issuer(new MpdfInvoiceInv2Renderer())->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
        $this->assertSame('418.50', $issued->total);

        $before = $this->state($issued->id);
        $docs   = new DocumentStore($this->rw());
        $doc    = $docs->read($issued->id);
        $this->assertNotNull($doc);
        $this->assertSame(hash('sha256', $doc->bytes), $doc->sha256);

        $legalBody = (string) $this->owner()->query("SELECT body FROM legal_texts WHERE country = 'SK' AND key = 'vat_note' AND language = 'sk' LIMIT 1")->getRow()->body;

        try {
            // subjekt: nova verzia (ina firma, adresa, splatnost) platna uz od datumu vystavenia
            $audit    = new AuditLogger($this->rw());
            $resolver = new EntityVersionResolver($this->rw());
            $version  = array_intersect_key($resolver->atDate($s['entity'], '2026-10-27'), array_flip(EntityInput::VERSION_FIELDS));
            $version  = ['trade_name' => 'Zmenena Firma s.r.o.', 'address_line1' => 'Ina 99', 'city' => 'Kosice', 'due_days' => '30'] + $version;
            (new EntityService($this->rw(), $audit))->newVersion($s['entity'], '2026-10-28', $version);
            $this->owner()->query('UPDATE entity_versions SET valid_from = :d: WHERE entity_id = :e: AND valid_from = :o:', ['d' => '2026-10-26', 'e' => $s['entity'], 'o' => '2026-10-28']);

            // klient: meno a sadzba cez sluzbu
            $clients = new ClientService($this->rw(), $audit);
            $client  = $clients->find($s['client']);
            $this->assertNotNull($client);
            $clients->update($s['client'], ['name' => 'Iny Klient', 'hourly_rate' => '99.00'] + $client);

            // rucny zasah ownera: mena klienta, sadzba a nazov ulohy, IBAN uctu, zakonny text
            $this->owner()->query("UPDATE clients SET currency = 'PLN' WHERE id = :id:", ['id' => $s['client']]);
            $this->owner()->query('UPDATE tasks SET rate = 99.00, title = :t: WHERE client_id = :id:', ['t' => 'Prepisany nazov', 'id' => $s['client']]);
            $this->owner()->query("UPDATE entity_bank_accounts SET iban = 'SK0211000000002612345678' WHERE entity_id = :e:", ['e' => $s['entity']]);
            $this->owner()->query("UPDATE legal_texts SET body = 'ZMENENE ZNENIE' WHERE country = 'SK' AND key = 'vat_note' AND language = 'sk'");

            $this->assertSame($before, $this->state($issued->id));

            $after = $docs->read($issued->id);
            $this->assertNotNull($after);
            $this->assertSame($doc->bytes, $after->bytes);
            $this->assertSame($doc->sha256, $after->sha256);
            $this->assertSame(hash('sha256', $after->bytes), $after->sha256);

            $which = trim((string) shell_exec('command -v pdftotext'));

            if ($which !== '') {
                $path = tempnam(sys_get_temp_dir(), 'inv');
                $this->assertIsString($path);
                file_put_contents($path, $after->bytes);
                $text = (string) shell_exec('pdftotext ' . escapeshellarg($path) . ' -');
                unlink($path);
                $this->assertStringContainsString('Jan Novak', $text);
                $this->assertStringNotContainsString('Zmenena Firma', $text);
                $this->assertStringContainsString('418', $text);
            }
        } finally {
            $this->owner()->query('UPDATE legal_texts SET body = :b: WHERE country = \'SK\' AND key = \'vat_note\' AND language = \'sk\'', ['b' => $legalBody]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function state(int $invoiceId): array
    {
        $invoice = $this->owner()->query('SELECT * FROM invoices WHERE id = :id:', ['id' => $invoiceId])->getRowArray();
        $lines   = $this->owner()->query('SELECT * FROM invoice_lines WHERE invoice_id = :id: ORDER BY id', ['id' => $invoiceId])->getResultArray();
        $snap    = json_decode((string) $invoice['snapshot'], true, 512, JSON_THROW_ON_ERROR);

        return ['invoice' => $invoice, 'snapshot' => $snap, 'lines' => $lines];
    }
}
