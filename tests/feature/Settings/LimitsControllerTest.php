<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Reporting\LimitMath;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\TurnoverFixtures;

/**
 * Obrazovka Nastavenia, Limity obratu: cerpanie so vzorcom (LIM-01, LIM-02, LIM-05, CUR-04).
 *
 * @internal
 */
#[Group('feature')]
final class LimitsControllerTest extends AuthTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;
    use TurnoverFixtures;

    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        $this->owner()->query('DELETE FROM turnover_limits');
        $this->client = $this->createClient();
        Services::injectMock('clock', new FrozenClock('2026-11-02T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    protected function tearDown(): void
    {
        $this->owner()->query('DELETE FROM turnover_limits');
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

    private function page(): string
    {
        $this->login();
        $result = $this->get('/settings/limits');
        $result->assertStatus(200);

        return $this->body($result);
    }

    private function eur(string $amount): string
    {
        return MoneyFormat::format($amount, 'EUR', 'sk');
    }

    private function pln(string $amount): string
    {
        return MoneyFormat::format($amount, 'PLN', 'sk');
    }

    public function testRequiresLogin(): void
    {
        $result = $this->get('/settings/limits');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testShowsUsageCardWithFormula(): void
    {
        $entity = $this->createEntity('SK');
        $this->insertLimit('SK', 2026, 'Registrácia DPH (kalendárny rok)', '50000.00', 'EUR', 'calendar_year');
        $this->turnoverInvoice($entity, $this->client, '2026-06-01', '41230.00');
        $this->insertOpening($entity, 2026, '3000.00');

        $html = $this->page();

        $this->assertStringContainsString('Moja firma SK', $html);
        $this->assertStringContainsString('Registrácia DPH (kalendárny rok)', $html);
        $this->assertStringContainsString(LimitMath::formatPercent('88.5', 'sk'), $html);
        $this->assertStringContainsString($this->eur('44230.00'), $html);
        $this->assertStringContainsString($this->eur('50000.00'), $html);
        $this->assertStringContainsString('Zostáva', $html);
        $this->assertStringContainsString($this->eur('5770.00'), $html);
        $this->assertStringContainsString('1. 1. 2026 – 31. 12. 2026', $html);
        $this->assertStringContainsString('Kalendárny rok', $html);
    }

    public function testStrictestFirst(): void
    {
        $entity = $this->createEntity('SK');
        $this->insertLimit('SK', 2026, 'Registrácia DPH (kalendárny rok)', '50000.00', 'EUR', 'calendar_year');
        $this->insertLimit('SK', 2026, 'Registrácia DPH (12 mesiacov)', '49790.00', 'EUR', 'rolling_12m');
        $this->turnoverInvoice($entity, $this->client, '2026-06-01', '41230.00');
        $this->insertOpening($entity, 2026, '3000.00');

        $html = $this->page();

        $first  = strpos($html, 'Registrácia DPH (12 mesiacov)');
        $second = strpos($html, 'Registrácia DPH (kalendárny rok)');
        $badge  = strpos($html, 'Prísnejšia hodnota');

        $this->assertNotFalse($first);
        $this->assertNotFalse($second);
        $this->assertNotFalse($badge);
        $this->assertLessThan($second, $first);
        $this->assertLessThan($first, $badge);
        $this->assertSame(1, substr_count($html, 'Prísnejšia hodnota'));
    }

    public function testProRataFormula(): void
    {
        $this->createEntity('PL', ['active_from' => '2026-07-15']);
        $this->insertLimit('PL', 2026, 'Zwolnienie z VAT', '240000.00', 'PLN', 'pro_rata_first_year');

        $html = $this->page();

        $this->assertStringContainsString('× 170 / 365', $html);
        $this->assertStringContainsString($this->pln('111780.82'), $html);
        $this->assertStringContainsString('Dni od 15. 7. 2026 vrátane, základ 365 dní.', $html);
    }

    public function testFallbackYearAlert(): void
    {
        $this->createEntity('SK');
        $this->insertLimit('SK', 2025, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');

        $html = $this->page();

        $this->assertStringContainsString('Limit pre rok 2026 chýba, použitý rok 2025', $html);
        $this->assertMatchesRegularExpression('/role="alert"[^>]*>\s*Limit pre rok 2026 chýba/', $html);
    }

    public function testCurrencyMismatch(): void
    {
        $this->createEntity('SK');
        $this->insertLimit('SK', 2026, 'Zlá mena', '50000.00', 'PLN', 'calendar_year');

        $html = $this->page();

        $this->assertStringContainsString('Chyba konfigurácie', $html);
        $this->assertStringContainsString('Obrat sa nepočíta', $html);
        $this->assertStringNotContainsString('<progress', $html);
    }

    public function testNoLimitForCountry(): void
    {
        $this->createEntity('SK');
        $this->insertLimit('PL', 2026, 'Zwolnienie z VAT', '240000.00', 'PLN', 'calendar_year');

        $html = $this->page();

        $this->assertStringContainsString('Pre krajinu Slovensko (SK) nie je zadaný žiadny limit.', $html);
    }

    public function testSeedBadgeAndNotice(): void
    {
        $this->createEntity('SK');
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year', '80,100', true);

        $html = $this->page();

        $this->assertStringContainsString('Návrh, neoverené účtovníčkou', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Návrh, neoverené účtovníčkou'));
        $this->assertStringContainsString('Predvyplnené limity sú návrh', $html);
    }

    public function testNoSeedNoNotice(): void
    {
        $this->createEntity('SK');
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');

        $html = $this->page();

        $this->assertStringNotContainsString('Predvyplnené limity sú návrh', $html);
        $this->assertStringNotContainsString('Návrh, neoverené účtovníčkou', $html);
    }

    public function testLimitListOrder(): void
    {
        $this->insertLimit('SK', 2025, 'Limit SK 2025', '40000.00', 'EUR', 'calendar_year');
        $this->insertLimit('SK', 2026, 'Limit SK 2026', '50000.00', 'EUR', 'calendar_year');
        $this->insertLimit('PL', 2026, 'Limit PL 2026', '240000.00', 'PLN', 'calendar_year');

        $html = $this->page();
        $from = (int) strpos($html, 'Tabuľka limitov');

        $this->assertGreaterThan(0, $from);

        $pl = strpos($html, 'Limit PL 2026', $from);
        $sk = strpos($html, 'Limit SK 2026', $from);
        $ol = strpos($html, 'Limit SK 2025', $from);

        $this->assertNotFalse($pl);
        $this->assertNotFalse($sk);
        $this->assertNotFalse($ol);
        $this->assertLessThan($sk, $pl);
        $this->assertLessThan($ol, $sk);
    }

    public function testMoreMenuLink(): void
    {
        $this->login();
        $result = $this->get('/more');
        $result->assertStatus(200);
        $this->assertStringContainsString('href="/settings/limits"', $this->body($result));
    }

    public function testCreditNoteReducesUsage(): void
    {
        $entity = $this->createEntity('SK');
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $id = $this->turnoverInvoice($entity, $this->client, '2026-03-01', '10000.00');
        $this->turnoverCreditNote($id, '2026-04-01');

        $html = $this->page();

        $this->assertStringContainsString($this->eur('0.00') . ' z ' . $this->eur('50000.00'), $html);
        $this->assertStringContainsString('1 faktúr, 1 dobropisov', $html);
    }
}
