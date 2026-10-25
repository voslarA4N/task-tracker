<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class EntitiesCreateTest extends AuthTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    /**
     * @param array<string, string> $over
     *
     * @return array<string, string>
     */
    private function skPost(array $over = []): array
    {
        return $over + [
            'name_internal' => 'Moja firma',
            'country'       => 'SK',
            'currency'      => 'EUR',
            'active_from'   => '2026-01-01',
            'active_to'     => '',
            'trade_name'    => 'Jan Novak Software',
            'address_line1' => 'Hlavna 1',
            'address_line2' => '',
            'postal_code'   => '81101',
            'city'          => 'Bratislava',
            'company_id'    => '12345678',
            'regon'         => '',
            'tax_id'        => '',
            'vat_id'        => '',
            'registry_note' => '',
            'email'         => 'firma@example.test',
            'phone'         => '',
            'doc_language'  => 'sk',
            'vat_regime'    => 'sk_non_vat',
            'due_days'      => '14',
        ];
    }

    /**
     * @param array<string, string> $data
     */
    private function submit(array $data, bool $csrf = true): TestResponse
    {
        $this->withSession();

        return $this->call('post', '/settings/entities', $csrf ? $data + [csrf_token() => csrf_hash()] : $data);
    }

    private function rows(string $table): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM {$table}")->getRow()->c;
    }

    public function testNewFormRenders(): void
    {
        $result = $this->get('/settings/entities/new');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString('name="country"', $html);
        $this->assertStringContainsString('name="currency"', $html);
        $this->assertStringContainsString('name="vat_regime"', $html);
        $this->assertStringContainsString('hx-get="/settings/entities/regime-fields', $html);
        $this->assertStringContainsString(esc(lang('Entities.immutableNotice')), $html);
    }

    public function testRegimeFieldsFragmentForPoland(): void
    {
        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/settings/entities/regime-fields?country=PL');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString('pl_exempt_113_1', $html);
        $this->assertStringContainsString('pl_exempt_113_9', $html);
        $this->assertStringNotContainsString('sk_non_vat', $html);
        $this->assertMatchesRegularExpression('/<option value="PLN" selected/', $html);
    }

    public function testCreateSlovakEntityRedirectsToDetail(): void
    {
        $result = $this->submit($this->skPost());
        $result->assertStatus(302);

        $id = (int) $this->owner()->query('SELECT id FROM entities')->getRow()->id;
        $this->assertStringEndsWith('/settings/entities/' . $id, $result->getRedirectUrl());

        $this->withSession();
        $detail = $this->get('/settings/entities/' . $id);
        $detail->assertStatus(200);
        $html = $this->body($detail);

        $this->assertStringContainsString('Jan Novak Software', $html);
        $this->assertStringContainsString('alert-success', $html);
        $this->assertStringContainsString(esc(lang('Entities.created')), $html);
        $this->assertStringContainsString(esc(lang('Entities.countrySK')), $html);
        $this->assertStringContainsString('EUR', $html);
        $this->assertStringNotContainsString('<select name="country"', $html);
        $this->assertStringNotContainsString('<input name="currency"', $html);
    }

    public function testCreatePolishEntity(): void
    {
        $result = $this->submit($this->skPost([
            'country'      => 'PL',
            'currency'     => 'PLN',
            'company_id'   => '5260250274',
            'regon'        => '123456785',
            'postal_code'  => '00-001',
            'doc_language' => 'pl',
            'vat_regime'   => 'pl_exempt_113_1',
        ]));

        $result->assertStatus(302);
        $this->assertSame(1, $this->rows('entities'));
    }

    public function testInvalidCountryRegimeCombinationReturns422(): void
    {
        $result = $this->submit($this->skPost(['vat_regime' => 'pl_exempt_113_1']));

        $result->assertStatus(422);
        $this->assertStringContainsString('is-invalid', $this->body($result));
        $this->assertSame(0, $this->rows('entities'));
        $this->assertSame(0, $this->rows('entity_versions'));
    }

    public function testPostWithoutCsrfIsForbidden(): void
    {
        $this->submit($this->skPost(), false)->assertStatus(403);
        $this->assertSame(0, $this->rows('entities'));
    }

    public function testUnknownEntityIs404(): void
    {
        // FeatureTestTrait prepúšťa PageNotFoundException (404 stránku renderuje handler výnimiek).
        try {
            $this->get('/settings/entities/999');
            $this->fail('Expected PageNotFoundException');
        } catch (PageNotFoundException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }
}
