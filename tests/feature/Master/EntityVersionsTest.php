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
final class EntityVersionsTest extends AuthTestCase
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
    private function data(array $over = []): array
    {
        return $over + [
            'valid_from'  => '2026-11-01', 'trade_name' => 'Jan Novak', 'address_line1' => 'Nova 2', 'address_line2' => '',
            'postal_code' => '81101', 'city' => 'Bratislava', 'company_id' => '12345678', 'regon' => '', 'tax_id' => '',
            'vat_id'      => '', 'registry_note' => '', 'email' => 'firma@example.test', 'phone' => '', 'doc_language' => 'sk',
            'vat_regime'  => 'sk_non_vat', 'due_days' => '14',
        ];
    }

    /**
     * @param array<string, string> $data
     */
    private function submit(int $id, array $data): TestResponse
    {
        $this->withSession();

        return $this->call('post', '/settings/entities/' . $id . '/versions', $data + [csrf_token() => csrf_hash()]);
    }

    private function versions(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM entity_versions')->getRow()->c;
    }

    public function testNewFormPrefilled(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->get('/settings/entities/' . $id . '/versions/new');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString('value="' . esc('Hlavna 1', 'attr') . '"', $html);
        $this->assertStringContainsString('name="valid_from"', $html);
        $this->assertStringContainsString('value="2026-10-25"', $html);
        $this->assertStringNotContainsString('name="country"', $html);
        $this->assertStringNotContainsString('name="currency"', $html);
        $this->assertStringContainsString('hx-get="/settings/entities/' . $id . '/versions/notice"', $html);
    }

    public function testFromPrefillsThatVersion(): void
    {
        $id  = $this->createEntity('SK');
        $vid = (int) $this->owner()->query('SELECT id FROM entity_versions WHERE entity_id = ?', [$id])->getRow()->id;

        $this->submit($id, $this->data())->assertStatus(302);

        $this->withSession();
        $html = $this->body($this->get('/settings/entities/' . $id . '/versions/new?from=' . $vid));
        $this->assertStringContainsString('value="' . esc('Hlavna 1', 'attr') . '"', $html);
    }

    public function testFromOfOtherEntityIs404(): void
    {
        $id    = $this->createEntity('SK');
        $other = $this->createEntity('PL');
        $vid   = (int) $this->owner()->query('SELECT id FROM entity_versions WHERE entity_id = ?', [$other])->getRow()->id;

        try {
            $this->get('/settings/entities/' . $id . '/versions/new?from=' . $vid);
            $this->fail('Expected PageNotFoundException');
        } catch (PageNotFoundException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function testCreateRedirectsAndDetailShowsHistory(): void
    {
        $id = $this->createEntity('SK');
        $this->submit($id, $this->data())->assertStatus(302);
        $this->assertSame(2, $this->versions());

        $this->withSession();
        $html = $this->body($this->get('/settings/entities/' . $id));

        $this->assertStringContainsString(esc(lang('Entities.badgeCurrent')), $html);
        $this->assertStringContainsString(esc(lang('Entities.badgeFuture')), $html);
        $this->assertStringContainsString('master-changed', $html);
        $this->assertStringContainsString('versions/new?from=', $html);
        $this->assertStringContainsString(esc(lang('Entities.historyTitle')), $html);
        $this->assertSame(0, preg_match('#versions/\d+/edit#', $html));
        $this->assertStringNotContainsString('value="delete"', $html);
    }

    public function testFlashAfterCreate(): void
    {
        $id = $this->createEntity('SK');
        $this->submit($id, $this->data())->assertStatus(302);

        $this->withSession();
        $html = $this->body($this->get('/settings/entities/' . $id));
        $this->assertStringContainsString('alert-success', $html);
    }

    public function testDuplicateDateReturns422(): void
    {
        $id = $this->createEntity('SK');
        $this->submit($id, $this->data())->assertStatus(302);

        $result = $this->submit($id, $this->data(['address_line1' => 'Ina 9']));
        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Entities.errorDuplicateDate')), $this->body($result));
        $this->assertSame(2, $this->versions());
    }

    public function testNoChangeReturns422(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->submit($id, $this->data(['address_line1' => 'Hlavna 1']));

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Entities.errorNoChange')), $this->body($result));
        $this->assertSame(1, $this->versions());
    }

    public function testOutOfRangeReturns422(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->submit($id, $this->data(['valid_from' => '2025-12-31']));

        $result->assertStatus(422);
        $this->assertSame(1, $this->versions());
    }

    public function testCountryCurrencyInPostRejected(): void
    {
        $id = $this->createEntity('SK');
        $this->submit($id, $this->data(['country' => 'PL', 'currency' => 'PLN']))->assertStatus(422);

        $row = $this->owner()->query('SELECT country, currency FROM entities WHERE id = ?', [$id])->getRow();
        $this->assertSame('SK', $row->country);
        $this->assertSame('EUR', $row->currency);
    }

    public function testNoticeFuture(): void
    {
        $id   = $this->createEntity('SK');
        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/settings/entities/' . $id . '/versions/notice?valid_from=2026-12-01'));

        $this->assertStringContainsString('alert-info', $html);
    }

    public function testNoticeLaterVersion(): void
    {
        $id = $this->createEntity('SK');
        $this->submit($id, $this->data())->assertStatus(302);

        $this->withSession();
        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/settings/entities/' . $id . '/versions/notice?valid_from=2026-10-26'));

        $this->assertStringContainsString('alert-warning', $html);
    }

    public function testNoticeWithoutHtmxRedirectsToForm(): void
    {
        $id = $this->createEntity('SK');

        $this->get('/settings/entities/' . $id . '/versions/notice?valid_from=2026-12-01')
            ->assertRedirectTo('/settings/entities/' . $id . '/versions/new');

        $this->withHeaders(['HX-Request' => 'true', 'HX-Boosted' => 'true'])
            ->get('/settings/entities/' . $id . '/versions/notice?valid_from=2026-12-01')
            ->assertRedirectTo('/settings/entities/' . $id . '/versions/new');
    }

    public function testNoEditOrDeleteRoutes(): void
    {
        $id = $this->createEntity('SK');

        $html = $this->body($this->get('/settings/entities/' . $id));
        $this->assertSame(0, preg_match('#versions/\d+/edit#', $html));
        $this->assertSame(0, preg_match('#method="post"[^>]*>\s*[^<]*<input[^>]*delete#i', $html));
    }
}
