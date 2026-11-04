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
 * Obrazovka Prehlady, mesiac (RPT-01).
 *
 * @internal
 */
#[Group('feature')]
final class ReportsPageTest extends AuthTestCase
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

    /**
     * @return array{int, int}
     */
    private function seed(): array
    {
        $entity = $this->createEntity('SK', ['active_from' => '2025-01-01']);
        $a      = $this->createClient(['name' => 'Klient Alfa']);
        $b      = $this->createClient(['name' => 'Klient Beta', 'identifier_value' => '22222222']);
        $this->reportInvoice($entity, $a, '2026-10-05', '1000.00');
        $this->reportInvoice($entity, $a, '2026-10-20', '80.00');
        $this->reportInvoice($entity, $b, '2026-10-12', '500.00');

        return [$entity, $a];
    }

    public function testRequiresLogin(): void
    {
        $result = $this->get('/reports/month');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testAccountantIsForbidden(): void
    {
        $this->login('accountant');
        $this->get('/reports/month')->assertStatus(403);
    }

    public function testShowsClientsAndTotals(): void
    {
        $this->seed();
        $this->login();
        $result = $this->get('/reports/month?period=2026-10');
        $result->assertStatus(200);
        $body = $this->body($result);

        $this->assertStringContainsString('Klient Alfa', $body);
        $this->assertStringContainsString('Klient Beta', $body);
        $this->assertStringContainsString(MoneyFormat::format('1080.00', 'EUR', 'sk'), $body);
        $this->assertStringContainsString(MoneyFormat::format('1580.00', 'EUR', 'sk'), $body);
        $this->assertStringContainsString(lang('Reports.total'), $body);
        $this->assertStringContainsString('id="report-body"', $body);
        $this->assertStringContainsString(lang('Reports.noteExternalExcluded'), $body);
    }

    public function testShowsPaidColumnAndPaymentOnlyMonthIsNotEmpty(): void
    {
        [$entity, $a] = $this->seed();
        $inv          = $this->reportInvoice($entity, $a, '2026-09-05', '300.00');
        $this->reportPayment($inv, '2026-10-02', '300.00');
        $this->login();

        $oct = $this->body($this->get('/reports/month?period=2026-10'));
        $this->assertStringContainsString(lang('Reports.colPaid'), $oct);
        $this->assertStringContainsString(MoneyFormat::format('300.00', 'EUR', 'sk'), $oct);

        $sep = $this->body($this->get('/reports/month?period=2026-09'));
        $this->assertStringNotContainsString(lang('Reports.emptyTitle'), $sep);
    }

    public function testHtmxReturnsFragment(): void
    {
        $this->seed();
        $this->login();
        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/reports/month?period=2026-10');
        $result->assertStatus(200);
        $body = $this->body($result);

        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringContainsString('id="report-body"', $body);
    }

    public function testInvalidPeriodRedirectsToCurrentMonth(): void
    {
        $this->seed();
        $this->login();
        $result = $this->get('/reports/month?period=2026-13');
        $result->assertStatus(302);
        $this->assertStringContainsString('/reports/month?period=2026-11', $result->response()->getHeaderLine('Location'));
    }

    public function testNextDisabledForCurrentMonth(): void
    {
        $this->seed();
        $this->login();
        $body = $this->body($this->get('/reports/month?period=2026-11'));

        $this->assertStringContainsString('aria-disabled="true"', $body);
    }

    public function testNextEnabledForPastMonth(): void
    {
        $this->seed();
        $this->login();
        $body = $this->body($this->get('/reports/month?period=2026-10'));

        $this->assertStringNotContainsString('aria-disabled="true"', $body);
        $this->assertStringContainsString('period=2026-11', $body);
        $this->assertStringContainsString('period=2026-09', $body);
    }

    public function testSingleEntityHasNoSelect(): void
    {
        $this->seed();
        $this->login();
        $this->assertStringNotContainsString('<select name="entity"', $this->body($this->get('/reports/month?period=2026-10')));
    }

    public function testTwoEntitiesHaveSelect(): void
    {
        $this->seed();
        $this->createEntity('SK', ['name_internal' => 'Druhy subjekt', 'active_from' => '2025-01-01']);
        $this->login();
        $this->assertStringContainsString('<select name="entity"', $this->body($this->get('/reports/month?period=2026-10')));
    }

    public function testNoEntity(): void
    {
        $this->login();
        $body = $this->body($this->get('/reports/month'));

        $this->assertStringContainsString(lang('Reports.noEntity'), $body);
        $this->assertStringContainsString('/settings/entities', $body);
    }

    public function testEmptyMonth(): void
    {
        $this->seed();
        $this->login();
        $this->assertStringContainsString(lang('Reports.emptyTitle'), $this->body($this->get('/reports/month?period=2026-03')));
    }

    public function testMoreMenuLinksToReports(): void
    {
        $this->login();
        $this->assertStringContainsString('href="/reports/month"', $this->body($this->get('/more')));
    }
}
