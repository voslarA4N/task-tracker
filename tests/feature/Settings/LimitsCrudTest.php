<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;
use Tests\Support\TurnoverFixtures;

/**
 * Pridanie, uprava a zmazanie limitu obratu z UI (LIM-01, LIM-05, D-01 az D-06, D-11).
 *
 * @internal
 */
#[Group('feature')]
final class LimitsCrudTest extends AuthTestCase
{
    use MasterFixtures;
    use TurnoverFixtures;

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
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function data(array $override = []): array
    {
        return $override + [
            'country'     => 'SK',
            'year'        => '2027',
            'name'        => 'Registrácia DPH',
            'amount'      => '50 000,00',
            'currency'    => 'EUR',
            'calc_method' => 'rolling_12m',
            'thresholds'  => '80, 100',
        ];
    }

    /**
     * @param array<string, string> $data
     */
    private function send(string $url, array $data, bool $htmx = false, bool $csrf = true): TestResponse
    {
        $this->withSession();

        if ($htmx) {
            $this->withHeaders(['HX-Request' => 'true']);
        }

        return $this->call('post', $url, $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    private function limitCount(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM turnover_limits')->getRow()->c;
    }

    private function audits(string $action): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'turnover_limit' AND action = :a:", ['a' => $action])->getRow()->c;
    }

    public function testNewFormSheetAndPage(): void
    {
        $page = $this->body($this->get('/settings/limits/new?country=SK&year=2027'));
        $this->assertStringContainsString('name="thresholds"', $page);
        $this->assertStringContainsString('<html', $page);
        $this->assertMatchesRegularExpression('/<option value="SK" selected>/', $page);
        $this->assertMatchesRegularExpression('/name="year"[^>]*value="2027"/', $page);

        $this->withHeaders(['HX-Request' => 'true']);
        $sheet = $this->body($this->get('/settings/limits/new'));
        $this->assertStringContainsString('dl-sheet', $sheet);
        $this->assertStringContainsString('name="thresholds"', $sheet);
    }

    public function testCreate(): void
    {
        $result = $this->send('/settings/limits', $this->data(), true);
        $result->assertStatus(200);
        $this->assertSame('/settings/limits', $result->response()->getHeaderLine('HX-Location'));
        $this->assertSame(1, $this->limitCount());
        $this->assertSame(1, $this->audits('create'));

        $list = $this->body($this->get('/settings/limits'));
        $this->assertStringContainsString('Registrácia DPH', $list);
        $this->assertStringContainsString('Kĺzavých 12 mesiacov', $list);
    }

    public function testCreateWithoutHtmxRedirects(): void
    {
        $result = $this->send('/settings/limits', $this->data());
        $result->assertStatus(302);
        $this->assertStringEndsWith('/settings/limits', $result->response()->getHeaderLine('Location'));
        $this->assertSame(1, $this->limitCount());
    }

    public function testCreateInvalid(): void
    {
        $result = $this->send('/settings/limits', $this->data(['amount' => '0', 'thresholds' => '80,250', 'name' => 'Mojnazov']));
        $result->assertStatus(422);
        $html = $this->body($result);
        $this->assertStringContainsString('Zadajte sumu väčšiu ako 0.', $html);
        $this->assertStringContainsString('Zadajte 1 až 3 celé čísla od 1 do 200 oddelené čiarkou.', $html);
        $this->assertMatchesRegularExpression('/is-invalid[^>]*name="amount"|name="amount"[^>]*is-invalid/', $html);
        $this->assertMatchesRegularExpression('/is-invalid[^>]*name="thresholds"|name="thresholds"[^>]*is-invalid/', $html);
        $this->assertStringContainsString('Mojnazov', $html);
        $this->assertSame(0, $this->limitCount());
    }

    public function testCreateDuplicate(): void
    {
        $this->insertLimit('SK', 2027, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $result = $this->send('/settings/limits', $this->data());
        $result->assertStatus(422);
        $this->assertStringContainsString('Limit s týmto názvom už pre krajinu a rok existuje.', $this->body($result));
        $this->assertSame(1, $this->limitCount());
    }

    public function testCurrencyWarningDoesNotBlock(): void
    {
        $this->createEntity('SK');
        $result = $this->send('/settings/limits', $this->data(['currency' => 'PLN']));
        $result->assertStatus(302);
        $this->assertSame(1, $this->limitCount());

        $bad    = $this->send('/settings/limits', $this->data(['currency' => 'PLN', 'name' => 'Iny', 'amount' => '0']));
        $bad->assertStatus(422);
        $this->assertStringContainsString('Mena nezodpovedá mene subjektu pre túto krajinu (EUR).', $this->body($bad));
    }

    public function testEditSeedClearsDraft(): void
    {
        $id = $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year', '80,100', true);
        $this->assertStringContainsString('Hodnota je predvyplnený návrh', $this->body($this->get('/settings/limits/' . $id . '/edit')));

        $result = $this->send('/settings/limits/' . $id, $this->data(['year' => '2026', 'revision' => '1']));
        $result->assertStatus(302);
        $row = $this->owner()->query('SELECT is_seed, revision FROM turnover_limits WHERE id = :id:', ['id' => $id])->getRow();
        $this->assertFalse(in_array($row->is_seed, [true, 't', '1', 1], true));
        $this->assertSame(2, (int) $row->revision);
    }

    public function testEditStale(): void
    {
        $id = $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $this->send('/settings/limits/' . $id, $this->data(['year' => '2026', 'revision' => '1']))->assertStatus(302);

        $result = $this->send('/settings/limits/' . $id, $this->data(['year' => '2026', 'revision' => '1']));
        $result->assertStatus(409);
        $this->assertStringContainsString('Limit sa medzitým zmenil.', $this->body($result));
    }

    public function testEditMissing404(): void
    {
        $this->expectException(PageNotFoundException::class);
        $this->get('/settings/limits/999999/edit');
    }

    public function testDelete(): void
    {
        $id   = $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $html = $this->body($this->get('/settings/limits/' . $id . '/delete'));
        $this->assertStringContainsString('Zmazať limit', $html);
        $this->assertStringContainsString('btn-danger', $html);

        $this->send('/settings/limits/' . $id . '/delete', ['revision' => '1'])->assertStatus(302);
        $this->assertSame(0, $this->limitCount());
        $this->assertSame(1, $this->audits('delete'));
        $this->assertStringContainsString('Limit Registrácia DPH bol zmazaný.', json_encode($_SESSION ?? [], JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testCsrfRequired(): void
    {
        $result = $this->send('/settings/limits', $this->data(), false, false);
        $this->assertGreaterThanOrEqual(400, $result->response()->getStatusCode());
        $this->assertSame(0, $this->limitCount());
    }

    public function testFallbackCardOffersAdd(): void
    {
        $this->createEntity('SK');
        $this->insertLimit('SK', 2025, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');

        $html = $this->body($this->get('/settings/limits'));
        $this->assertStringContainsString('/settings/limits/new?country=SK&amp;year=2026', $html);
        $this->assertStringContainsString('Pridať limit pre rok 2026', $html);
    }

    public function testListHasActions(): void
    {
        $id   = $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $html = $this->body($this->get('/settings/limits'));

        $this->assertStringContainsString('/settings/limits/' . $id . '/edit', $html);
        $this->assertStringContainsString('/settings/limits/' . $id . '/delete', $html);
        $this->assertMatchesRegularExpression('/btn btn-primary[^>]*>\s*Pridať limit/', $html);
    }
}
