<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class CreditNoteDeliverySheetTest extends AuthTestCase
{
    use CreditNoteFixtures;
    use DeliveryFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));
        Services::injectMock('creditNoteIssuer', $this->creditNoteIssuer());
        $this->resetMasterTables();
        $this->configureMail('log');
    }

    protected function tearDown(): void
    {
        $this->resetMail();
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function login(): void
    {
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    public function testSendSheetHasCreditNoteTitleAndOnlyCreditNotePdf(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $cn  = $this->issueCreditNote($inv['id']);

        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/invoices/' . $cn->id . '/send'));
        $this->assertStringContainsString('Odoslať dobropis', $html);
        $this->assertStringContainsString('Dobropis_' . $cn->number . '.pdf', $html);
        $this->assertStringNotContainsString('Vykaz_', $html);

        $page = $this->body($this->get('/invoices/' . $cn->id . '/send'));
        $this->assertStringContainsString('Odoslať dobropis', $page);
    }

    public function testResendSheetHasCreditNoteResendTitle(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $cn  = $this->issueCreditNote($inv['id']);

        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/invoices/' . $cn->id . '/resend'));
        $this->assertStringContainsString('Odoslať dobropis znova', $html);
    }

    public function testInvoiceSendSheetKeepsOriginalTitle(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();

        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/invoices/' . $inv['id'] . '/send'));
        $this->assertStringContainsString('Odoslať klientovi', $html);
        $this->assertStringNotContainsString('dobropis', $html);
    }
}
