<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
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
use Tests\Support\XlsxReader;

/**
 * CSV export prehladov (RPT-02, D-06 az D-08).
 *
 * @internal
 */
#[Group('feature')]
final class ExportEndpointsTest extends AuthTestCase
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
        $a      = $this->createClient(['name' => '=1+1']);
        $b      = $this->createClient(['name' => 'Klient Beta', 'identifier_value' => '22222222']);
        $this->reportInvoice($entity, $a, '2026-10-10', '1000.00');
        $inv = $this->reportInvoice($entity, $b, '2026-10-12', '500.00');
        $this->reportPayment($inv, '2026-10-20', '200.00');
        $this->turnoverCreditNote($inv, '2026-10-25');
    }

    public function testMonthCsv(): void
    {
        $this->seed();
        $this->login();
        $result = $this->get('/reports/month.csv?period=2026-10');
        $result->assertStatus(200);
        $res = $result->response();

        $this->assertSame('text/csv; charset=UTF-8', $res->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="prehlad-2026-10-moja-firma-sk.csv"', $res->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));

        $body = $this->body($result);
        $this->assertStringStartsWith("\xEF\xBB\xBFKlient;Vyfakturované;Uhradené;Mena", $body);
        $this->assertStringContainsString("'=1+1;1000,00;0,00;EUR", $body);
        $this->assertStringContainsString('"Klient Beta";0,00;200,00;EUR', $body);
        $this->assertMatchesRegularExpression('/^Spolu;[^;]+;[^;]+;EUR$/m', $body);
    }

    public function testCreditNoteNetsAndNumbersAreNotPrefixed(): void
    {
        $this->seed();
        $this->login();
        $body = $this->body($this->get('/reports/month.csv?period=2026-10'));

        $this->assertStringContainsString('"Klient Beta";0,00;200,00;EUR', $body);
        $this->assertStringNotContainsString("'-", $body);
        $this->assertStringNotContainsString("';", $body);
    }

    public function testYearCsv(): void
    {
        $this->seed();
        $this->login();
        $result = $this->get('/reports/year.csv?year=2026');
        $result->assertStatus(200);

        $this->assertSame('attachment; filename="prehlad-2026-moja-firma-sk.csv"', $result->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame(12, preg_match_all('/^2026-\d{2};"Všetci klienti";/m', $this->body($result)));
    }

    public function testInvalidPeriodIs404(): void
    {
        $this->seed();
        $this->login();
        $this->expectException(PageNotFoundException::class);
        $this->get('/reports/month.csv?period=abc');
    }

    public function testAccountantIsForbidden(): void
    {
        $this->seed();
        $this->login('accountant');
        $this->get('/reports/month.csv?period=2026-10')->assertStatus(403);
    }

    public function testMonthPageHasDownloadLink(): void
    {
        $this->seed();
        $this->login();
        $body = $this->body($this->get('/reports/month?period=2026-10'));

        $this->assertMatchesRegularExpression('#href="/reports/month\.csv\?[^"]*" download#', $body);
    }

    public function testMonthXlsx(): void
    {
        $this->seed();
        $this->login();
        $result = $this->get('/reports/month.xlsx?period=2026-10');
        $result->assertStatus(200);
        $res = $result->response();

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $res->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="prehlad-2026-10-moja-firma-sk.xlsx"', $res->getHeaderLine('Content-Disposition'));

        $body = $this->body($result);
        $this->assertSame('PK', substr($body, 0, 2));
        $this->assertStringNotContainsString('<f>', XlsxReader::sheetXml($body));
        $cells = XlsxReader::cells($body);
        $this->assertSame('inlineStr', $cells[1][0]['type']);
        $this->assertSame('=1+1', $cells[1][0]['value']);
    }

    public function testYearXlsx(): void
    {
        $this->seed();
        $this->login();
        $result = $this->get('/reports/year.xlsx?year=2026');
        $result->assertStatus(200);

        $this->assertSame('attachment; filename="prehlad-2026-moja-firma-sk.xlsx"', $result->response()->getHeaderLine('Content-Disposition'));
    }

    public function testXlsxAccountantIsForbidden(): void
    {
        $this->seed();
        $this->login('accountant');
        $this->get('/reports/month.xlsx?period=2026-10')->assertStatus(403);
    }

    public function testMonthPageHasXlsxLink(): void
    {
        $this->seed();
        $this->login();
        $this->assertStringContainsString('href="/reports/month.xlsx?', $this->body($this->get('/reports/month?period=2026-10')));
    }
}
