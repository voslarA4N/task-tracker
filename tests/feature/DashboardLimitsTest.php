<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\Platform\FrozenClock;
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
 * Blok Limity obratu na dashboarde (LIM-03, D-15).
 *
 * @internal
 */
#[Group('feature')]
final class DashboardLimitsTest extends AuthTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;
    use TurnoverFixtures;

    private int $client;
    private int $entity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        $this->owner()->query('DELETE FROM turnover_limits');
        $this->entity = $this->createEntity('SK');
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

    private function home(string $query = ''): string
    {
        $this->login();

        return $this->body($this->get('/' . $query));
    }

    public function testNoBlockWithoutReason(): void
    {
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $this->turnoverInvoice($this->entity, $this->client, '2026-06-01', '10000.00');

        $this->assertStringNotContainsString('id="limits-title"', $this->home());
    }

    public function testWarnAt80(): void
    {
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $this->turnoverInvoice($this->entity, $this->client, '2026-06-01', '40000.00');

        $html = $this->home();

        $this->assertStringContainsString('id="limits-title"', $html);
        $this->assertStringContainsString('Blíži sa limit', $html);
        $this->assertStringContainsString('lim-alert--warn', $html);
        $this->assertStringContainsString('80,0', $html);
        $this->assertStringContainsString('href="/settings/limits"', $html);
    }

    public function testCritAt100(): void
    {
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $this->turnoverInvoice($this->entity, $this->client, '2026-06-01', '50000.00');

        $html = $this->home();

        $this->assertStringContainsString('Limit dosiahnutý', $html);
        $this->assertStringContainsString('lim-alert--crit', $html);
    }

    public function testMissingYearInfo(): void
    {
        $this->insertLimit('SK', 2025, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');

        $html = $this->home();

        $this->assertStringContainsString('limit pre rok 2026 chýba, použitý rok 2025', $html);
        $this->assertStringContainsString('lim-alert--info', $html);

        // Layout ma vlastne zatvaracie prvky, kontroluje sa iba samotny blok limitov.
        $start = strpos($html, 'aria-labelledby="limits-title"');
        $end   = strpos($html, '</section>', (int) $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $block = substr($html, $start, $end - $start);
        $this->assertStringNotContainsString('btn-close', $block);
        $this->assertStringNotContainsString('data-bs-dismiss', $block);
    }

    public function testMultipleRows(): void
    {
        $this->insertLimit('SK', 2026, 'Limit A', '50000.00', 'EUR', 'calendar_year');
        $this->insertLimit('SK', 2026, 'Limit B', '62500.00', 'EUR', 'calendar_year', '100');
        $this->turnoverInvoice($this->entity, $this->client, '2026-06-01', '52000.00');

        $html = $this->home();

        $this->assertStringContainsString('Limit A', $html);
        $this->assertStringNotContainsString('Limit B', $html);
    }

    public function testIndependentOfClientFilter(): void
    {
        $other = $this->createClient(['name' => 'Iny klient']);
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $this->turnoverInvoice($this->entity, $this->client, '2026-06-01', '40000.00');

        $html = $this->home('?client=' . $other);

        $this->assertStringContainsString('id="limits-title"', $html);
    }

    public function testCurrencyMismatch(): void
    {
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'PLN', 'calendar_year');

        $this->assertStringContainsString('Chyba konfigurácie', $this->home());
    }

    public function testFragmentBody(): void
    {
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $this->turnoverInvoice($this->entity, $this->client, '2026-06-01', '40000.00');
        $this->login();

        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/fragments/dashboard?month=2026-10&client=all');

        $this->assertStringContainsString('lim-alert--warn', $this->body($result));
    }
}
