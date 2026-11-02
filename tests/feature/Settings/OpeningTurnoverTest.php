<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\MoneyFormat;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;
use Tests\Support\TurnoverFixtures;

/**
 * Zadanie poctoveho obratu za subjekt a rok z UI (LIM-02, D-06, D-07).
 *
 * @internal
 */
#[Group('feature')]
final class OpeningTurnoverTest extends AuthTestCase
{
    use MasterFixtures;
    use TurnoverFixtures;

    private int $entity = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        $this->owner()->query('DELETE FROM turnover_limits');
        Services::injectMock('clock', new FrozenClock('2026-11-02T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);

        $this->entity = $this->createEntity('SK');
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
    }

    protected function tearDown(): void
    {
        $this->owner()->query('DELETE FROM turnover_limits');
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function body(TestResponse $r): string
    {
        return (string) $r->response()->getBody();
    }

    /**
     * @param array<string, string> $data
     */
    private function send(array $data, bool $htmx = true, bool $csrf = true): TestResponse
    {
        $this->withSession();

        if ($htmx) {
            $this->withHeaders(['HX-Request' => 'true']);
        }

        return $this->call('post', '/settings/limits/opening', $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function data(array $override = []): array
    {
        return $override + [
            'entity_id' => (string) $this->entity,
            'year'      => '2026',
            'amount'    => '3 000,00',
            'note'      => 'Excel do 30. 9. 2026',
            'revision'  => '',
        ];
    }

    private function openingCount(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM entity_opening_turnover')->getRow()->c;
    }

    public function testSectionListsCurrentYearUnset(): void
    {
        $html = $this->body($this->get('/settings/limits'));
        $this->assertStringContainsString('Počiatočný obrat', $html);
        $this->assertStringContainsString('nezadaný', $html);
        $this->assertStringContainsString('/settings/limits/opening?entity=' . $this->entity . '&amp;year=2026', $html);
    }

    public function testSectionListsExistingYears(): void
    {
        $this->insertOpening($this->entity, 2025, '1200.00');
        $html = $this->body($this->get('/settings/limits'));
        $this->assertStringContainsString('/settings/limits/opening?entity=' . $this->entity . '&amp;year=2025', $html);
        $this->assertStringContainsString('/settings/limits/opening?entity=' . $this->entity . '&amp;year=2026', $html);
        $this->assertStringContainsString(esc(MoneyFormat::format('1200.00', 'EUR', 'sk')), $html);
    }

    public function testFormSheetShowsEntityCurrency(): void
    {
        $this->withHeaders(['HX-Request' => 'true']);
        $html = $this->body($this->get('/settings/limits/opening?entity=' . $this->entity . '&year=2026'));
        $this->assertStringContainsString('dl-sheet', $html);
        $this->assertStringContainsString('input-group-text', $html);
        $this->assertStringContainsString('€', $html);
        $this->assertMatchesRegularExpression('/<select[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/type="hidden" name="entity_id" value="' . $this->entity . '"/', $html);
        $this->assertStringNotContainsString('name="currency"', $html);
    }

    public function testSaveCreatesAndCardUsesIt(): void
    {
        $result = $this->send($this->data());
        $result->assertStatus(200);
        $this->assertSame('/settings/limits', $result->response()->getHeaderLine('HX-Location'));
        $this->assertSame(1, $this->openingCount());

        $html = $this->body($this->get('/settings/limits'));
        $this->assertStringContainsString('Počiatočný obrat bol uložený.', $html);
        $this->assertStringContainsString(esc(MoneyFormat::format('3000.00', 'EUR', 'sk')), $html);
        $this->assertStringContainsString('Počiatočný obrat: ' . esc(MoneyFormat::format('3000.00', 'EUR', 'sk')), $html);
    }

    public function testCurrencyFieldIgnored(): void
    {
        $this->send($this->data(['currency' => 'PLN']))->assertStatus(200);
        $this->assertSame(1, $this->openingCount());
        $html = $this->body($this->get('/settings/limits'));
        $this->assertStringContainsString('Počiatočný obrat: ' . esc(MoneyFormat::format('3000.00', 'EUR', 'sk')), $html);
    }

    public function testEmptyAmountDeletes(): void
    {
        $this->insertOpening($this->entity, 2026, '1500.00');
        $this->send($this->data(['amount' => '', 'revision' => '1']))->assertStatus(200);
        $this->assertSame(0, $this->openingCount());
        $audit = (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'entity_opening_turnover' AND action = 'delete'")->getRow()->c;
        $this->assertSame(1, $audit);
    }

    public function testInvalid(): void
    {
        $bad = $this->send($this->data(['amount' => '-5']));
        $bad->assertStatus(422);
        $this->assertStringContainsString('Zadajte sumu 0 alebo vyššiu.', $this->body($bad));

        $year = $this->send($this->data(['year' => '1999']));
        $year->assertStatus(422);
        $this->assertStringContainsString('Zadajte rok od 2000 do 2100.', $this->body($year));
        $this->assertSame(0, $this->openingCount());
    }

    public function testStale(): void
    {
        $this->insertOpening($this->entity, 2026, '1500.00');
        $result = $this->send($this->data(['revision' => '7']));
        $result->assertStatus(409);
        $this->assertStringContainsString('Počiatočný obrat sa medzitým zmenil.', $this->body($result));
    }

    public function testNoJsSaveRedirects(): void
    {
        $result = $this->send($this->data(), false);
        $result->assertStatus(302);
        $this->assertSame(1, $this->openingCount());
    }

    public function testCsrfRequired(): void
    {
        try {
            $result = $this->send($this->data(), true, false);
            $this->assertNotSame(200, $result->response()->getStatusCode());
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->openingCount());
    }
}
