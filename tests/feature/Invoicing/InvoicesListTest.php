<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
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
final class InvoicesListTest extends AuthTestCase
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

    public function testEmptyState(): void
    {
        $this->login();
        $html = $this->body($this->get('/invoices'));

        $this->assertStringContainsString('Zatiaľ žiadne faktúry', $html);
        $this->assertStringContainsString('href="/invoices/new"', $html);
        $this->assertStringContainsString('Fakturovať mesiac', $html);
        $this->assertStringContainsString('<a href="/invoices" aria-current="page">', $html);
    }

    public function testListShowsIssuedAndExternal(): void
    {
        $this->login();
        $s = $this->issueSetup(['client' => ['name' => 'Klient Alfa']]);
        $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
        $task = $this->createTask($s['client']);
        $this->lockAllocation($this->createAllocation($task, '8.00', '2026-09'));

        $html = $this->body($this->get('/invoices'));

        $this->assertStringContainsString('202610001', $html);
        $this->assertStringContainsString('Vystavená', $html);
        $this->assertStringContainsString('Klient Alfa', $html);
        $this->assertStringContainsString('Externá', $html);
        $this->assertStringContainsString('Externá faktúra', $html);
        $this->assertStringNotContainsString('style="', $html);
    }

    public function testHtmxReturnsFragmentWithVary(): void
    {
        $this->login();
        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/invoices');

        $this->assertStringNotContainsString('<html', $this->body($result));
        $this->assertStringContainsString('id="invoice-list"', $this->body($result));
        $this->assertStringContainsString('HX-Request', $result->response()->getHeaderLine('Vary'));
    }

    public function testAnonymousIsRedirected(): void
    {
        $result = $this->get('/invoices');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }
}
