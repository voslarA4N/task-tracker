<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\MoneyFormat;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\ReportFixtures;
use Tests\Support\TurnoverFixtures;

/**
 * Obrazovka Prehlady, rok (RPT-01, D-04).
 *
 * @internal
 */
#[Group('feature')]
final class ReportsYearPageTest extends AuthTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;
    use ReportFixtures;
    use TurnoverFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function login(string $group = 'admin'): void
    {
        $user = $this->createUser('owner@example.test', $group);
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function seed(): void
    {
        $entity = $this->createEntity('SK', ['active_from' => '2025-01-01']);
        $a      = $this->createClient(['name' => 'Klient Alfa']);
        $b      = $this->createClient(['name' => 'Klient Beta', 'identifier_value' => '22222222']);
        $this->reportInvoice($entity, $a, '2026-01-10', '1000.00');
        $inv = $this->reportInvoice($entity, $b, '2026-10-12', '500.00');
        $this->reportPayment($inv, '2026-11-02', '500.00');
    }

    public function testAccountantIsForbidden(): void
    {
        $this->login('accountant');
        $this->get('/reports/year')->assertStatus(403);
    }

    public function testShowsTwelveMonthsTotalsAndClients(): void
    {
        $this->seed();
        $this->login();
        $result = $this->get('/reports/year?year=2026');
        $result->assertStatus(200);
        $body = $this->body($result);

        $this->assertSame(12, substr_count($body, 'class="rpt-month-link"'));
        $this->assertStringContainsString('period=2026-12', $body);
        $this->assertStringContainsString(MoneyFormat::format('0.00', 'EUR', 'sk'), $body);
        $this->assertStringContainsString(MoneyFormat::format('1500.00', 'EUR', 'sk'), $body);
        $this->assertStringContainsString(MoneyFormat::format('500.00', 'EUR', 'sk'), $body);
        $this->assertStringContainsString(lang('Reports.sectionByClient'), $body);
        $this->assertStringContainsString('Klient Alfa', $body);
        $this->assertStringContainsString('Klient Beta', $body);
    }

    public function testInvalidYearRedirects(): void
    {
        $this->seed();
        $this->login();
        $result = $this->get('/reports/year?year=abc');
        $result->assertStatus(302);
        $this->assertStringContainsString('/reports/year?year=2026', $result->response()->getHeaderLine('Location'));
    }

    public function testNextDisabledForCurrentYear(): void
    {
        $this->seed();
        $this->login();
        $this->assertStringContainsString('aria-disabled="true"', $this->body($this->get('/reports/year?year=2026')));
    }

    public function testNextLinkForPastYear(): void
    {
        $this->seed();
        $this->login();
        $body = $this->body($this->get('/reports/year?year=2025'));

        $this->assertStringNotContainsString('aria-disabled="true"', $body);
        $this->assertStringContainsString('year=2026', $body);
        $this->assertStringContainsString('year=2024', $body);
    }

    public function testHtmxReturnsFragment(): void
    {
        $this->seed();
        $this->login();
        $body = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/reports/year?year=2026'));

        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringContainsString('id="report-body"', $body);
    }

    public function testSwitchBetweenMonthAndYear(): void
    {
        $this->seed();
        $this->login();
        $month = $this->body($this->get('/reports/month?period=2026-10'));
        $this->assertStringContainsString('href="/reports/year?', $month);
        $this->assertMatchesRegularExpression('/<a[^>]*aria-current="page"[^>]*>' . preg_quote(lang('Reports.tabMonth'), '/') . '</', $month);

        $year = $this->body($this->get('/reports/year?year=2026'));
        $this->assertMatchesRegularExpression('/<a[^>]*aria-current="page"[^>]*>' . preg_quote(lang('Reports.tabYear'), '/') . '</', $year);
        $this->assertStringContainsString('href="/reports/month?', $year);
    }
}
