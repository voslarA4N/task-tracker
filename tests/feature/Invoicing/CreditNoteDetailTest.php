<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\FxFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class CreditNoteDetailTest extends AuthTestCase
{
    use CreditNoteFixtures;
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));
        Services::injectMock('creditNoteIssuer', $this->creditNoteIssuer());
        $this->resetMasterTables();
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function advanceClock(string $iso): void
    {
        Services::injectMock('clock', new FrozenClock($iso));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function invoice(): int
    {
        $s = $this->issueSetup();

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
    }

    private function markSent(int $id): void
    {
        $this->owner()->query("INSERT INTO invoice_sends (invoice_id, kind, sent_at, transport, counts_as_sent, created_at) VALUES (:i:, 'invoice', now(), 'smtp', true, now())", ['i' => $id]);
    }

    public function testIssuedInvoiceOffersCreditNoteAsLastButton(): void
    {
        $id   = $this->invoice();
        $html = $this->body($this->get('/invoices/' . $id));

        $this->assertStringContainsString('href="/invoices/' . $id . '/credit-note"', $html);
        $this->assertStringContainsString('Vystaviť dobropis', $html);
        $this->assertMatchesRegularExpression('#class="btn btn-outline-secondary btn-touch[^"]*" href="/invoices/' . $id . '/credit-note"#', $html);
    }

    public function testPaidInvoiceStillOffersCreditNote(): void
    {
        $id = $this->invoice();
        $this->owner()->query('INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, :d:, :a:, now())', ['i' => $id, 'd' => '2026-10-30', 'a' => '1080.00']);

        $this->assertStringContainsString('/credit-note', $this->body($this->get('/invoices/' . $id)));
    }

    public function testExternalInvoiceHasNoCreditNoteButton(): void
    {
        $s  = $this->issueSetup();
        $id = $this->insertExternalInvoice($s['client'], '2026-10', [$this->createAllocation($this->createTask($s['client']), '8.00', '2026-10', ['rate' => '135.00', 'amount' => '1080.00'])], 'EXT-1');

        $this->assertStringNotContainsString('/credit-note', $this->body($this->get('/invoices/' . $id)));
    }

    public function testCancelledInvoiceDetail(): void
    {
        $orig = $this->invoice();
        $this->markSent($orig);
        $this->owner()->query('INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, :d:, :a:, now())', ['i' => $orig, 'd' => '2026-10-30', 'a' => '10.00']);
        $cn = $this->issueCreditNote($orig);
        $this->advanceClock('2026-12-31T10:00:00+01:00');
        $html = $this->body($this->get('/invoices/' . $orig));

        $this->assertStringContainsString('Stornovaná', $html);
        $this->assertStringContainsString('cn-banner', $html);
        $this->assertStringContainsString('Stornovaná dobropisom č. ' . $cn->number, $html);
        $this->assertStringContainsString('href="/invoices/' . $cn->id . '"', $html);
        $this->assertStringContainsString('Po zohľadnení dobropisu: 0,00', $html);
        $this->assertStringNotContainsString('Po splatnosti', $html);
        $this->assertStringNotContainsString('/credit-note', $html);
        $this->assertStringNotContainsString('/remind', $html);
        $this->assertStringNotContainsString('href="/invoices/' . $orig . '/payments"', $html);
        $this->assertStringNotContainsString('/void"', $html);
        $this->assertStringContainsString('Úhrady sa už nedajú meniť.', $html);
        $this->assertStringContainsString('/invoices/' . $orig . '/pdf', $html);
        $this->assertStringContainsString('/invoices/' . $orig . '/report', $html);
        $this->assertStringNotContainsString('/invoices/' . $orig . '/resend', $html);
        $this->assertStringNotContainsString('/invoices/' . $orig . '/send', $html);
        $this->assertStringNotContainsString('/invoices/' . $orig . '/mark-sent', $html);
    }

    public function testCancelledUnsentInvoiceHasNoDeliveryButtonsAndFormsAre404(): void
    {
        $orig = $this->invoice();
        $this->issueCreditNote($orig);
        $html = $this->body($this->get('/invoices/' . $orig));

        $this->assertStringNotContainsString('/invoices/' . $orig . '/send', $html);
        $this->assertStringNotContainsString('/invoices/' . $orig . '/mark-sent', $html);
        $this->assertStringNotContainsString('/invoices/' . $orig . '/resend', $html);

        foreach (['/send', '/resend', '/mark-sent'] as $suffix) {
            try {
                $this->get('/invoices/' . $orig . $suffix);
                $this->fail('Expected PageNotFoundException for ' . $suffix);
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCreditNoteDetail(): void
    {
        $orig = $this->invoice();
        $cn   = $this->issueCreditNote($orig, ['reason' => '<b>x</b> chyba', 'correctionType' => CorrectionType::AmountError]);
        $html = $this->body($this->get('/invoices/' . $cn->id));

        $this->assertStringContainsString('Dobropis', $html);
        $this->assertStringContainsString('Vystavený', $html);
        $this->assertStringContainsString('cn-banner', $html);
        $this->assertStringContainsString('Opravuje faktúru č. 202610001', $html);
        $this->assertStringContainsString('href="/invoices/' . $orig . '"', $html);
        $this->assertStringContainsString('Chybná suma, hodiny alebo sadzba', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt; chyba', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('Celé storno', $html);
        $this->assertStringContainsString('-1', $html);
        $this->assertStringContainsString('Dobropis PDF', $html);
        $this->assertStringContainsString('/invoices/' . $cn->id . '/pdf', $html);
        $this->assertStringContainsString('/invoices/' . $cn->id . '/send', $html);
        $this->assertStringNotContainsString('/report', $html);
        $this->assertStringNotContainsString('/payments', $html);
        $this->assertStringNotContainsString('/remind', $html);
        $this->assertStringNotContainsString('/credit-note', $html);
        $this->assertStringNotContainsString('Po splatnosti', $html);
    }

    public function testCreditNoteForeignCurrencyShowsRate(): void
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');
        $orig = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-30']))->id;
        $cn   = $this->issueCreditNote($orig);
        $html = $this->body($this->get('/invoices/' . $cn->id));

        $this->assertStringContainsString('Kurz', $html);
        $this->assertStringContainsString('fx-sub', $html);
        $this->assertStringContainsString('-', $html);
    }
}
