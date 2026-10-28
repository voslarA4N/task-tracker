<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\MoneyFormat;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class InvoiceDetailTest extends AuthTestCase
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

    public function testIssuedDetail(): void
    {
        $this->login();
        $s      = $this->issueSetup();
        $issued = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
        $html   = $this->body($this->get('/invoices/' . $issued->id));

        $this->assertMatchesRegularExpression('#<h1[^>]*>\s*202610001\s*</h1>#', $html);
        $this->assertStringContainsString('Celkom', $html);
        $this->assertStringContainsString(esc(MoneyFormat::format('1080.00', 'EUR')), $html);
        $this->assertStringContainsString('href="/invoices/' . $issued->id . '/pdf" target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('href="/invoices/' . $issued->id . '/report" target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('Faktúra PDF', $html);
        $this->assertStringContainsString('Výkaz PDF', $html);
        $this->assertStringNotContainsString('Pre túto faktúru výkaz nebol vytvorený', $html);
        $sha = (string) $this->owner()->query('SELECT sha256 FROM invoice_documents WHERE invoice_id = :i:', ['i' => $issued->id])->getRow()->sha256;
        $this->assertStringContainsString(substr(trim($sha), 0, 16), $html);
        $this->assertStringContainsString('Vystavená faktúra je nemenná', $html);
        $this->assertStringContainsString('href="/tasks/' . $s['tasks'][0] . '"', $html);
        $this->assertStringNotContainsString('Upraviť', $html);
        $this->assertStringNotContainsString('Zmazať', $html);
        $this->assertStringNotContainsString('method="post"', $html);
    }

    public function testSchema1DetailShowsNoReportInfoInsteadOfButton(): void
    {
        $this->login();
        $s    = $this->issueSetup();
        $id   = $this->insertIssuedInvoiceRaw($s['client'], $s['entity'], '2026-10', $s['parts']);
        $html = $this->body($this->get('/invoices/' . $id));

        $this->assertStringContainsString('Pre túto faktúru výkaz nebol vytvorený', $html);
        $this->assertStringNotContainsString('/report"', $html);
        $this->assertStringContainsString('href="/invoices/' . $id . '/pdf"', $html);
    }

    public function testExternalDetailWithHttpLink(): void
    {
        $this->login();
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '8.00', '2026-09');
        $id     = $this->insertExternalInvoice($client, '2026-09', [$alloc], 'https://example.com/doc');
        $html   = $this->body($this->get('/invoices/' . $id));

        $this->assertStringContainsString('Otvoriť doklad', $html);
        $this->assertStringContainsString('href="https://example.com/doc"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('/pdf', $html);
        $this->assertStringNotContainsString('Zobraziť PDF', $html);
    }

    public function testExternalDetailRejectsJavascriptLink(): void
    {
        $this->login();
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '8.00', '2026-09');
        $id     = $this->insertExternalInvoice($client, '2026-09', [$alloc], 'javascript:alert(1)');
        $html   = $this->body($this->get('/invoices/' . $id));

        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringNotContainsString('Otvoriť doklad', $html);
        $this->assertStringContainsString('javascript:alert(1)', $html);
    }

    public function testMissingInvoiceIs404(): void
    {
        $this->login();
        $this->expectException(PageNotFoundException::class);
        $this->get('/invoices/999999');
    }
}
