<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class EntityImmutableTest extends AuthTestCase
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

    /**
     * @param array<string, string> $data
     */
    private function submit(string $path, array $data): TestResponse
    {
        $this->withSession();

        return $this->call('post', $path, $data + [csrf_token() => csrf_hash()]);
    }

    #[DataProvider('provideUpdateRejectsForgedKey')]
    public function testUpdateRejectsForgedKey(string $key, string $value): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->submit('/settings/entities/' . $id, [
            'name_internal' => 'Zmenený', 'active_from' => '2026-01-01', 'active_to' => '', $key => $value,
        ]);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Entities.errorImmutable')), (string) $result->response()->getBody());

        $row = $this->owner()->query('SELECT country, currency, name_internal FROM entities WHERE id = ?', [$id])->getRow();
        $this->assertSame('SK', $row->country);
        $this->assertSame('EUR', $row->currency);
        $this->assertSame('Moja firma SK', $row->name_internal);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideUpdateRejectsForgedKey(): iterable
    {
        yield 'country' => ['country', 'PL'];

        yield 'currency' => ['currency', 'PLN'];

        yield 'empty country' => ['country', ''];
    }

    public function testNewVersionRejectsCurrency(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->submit('/settings/entities/' . $id . '/versions', [
            'valid_from'  => '2026-11-01', 'trade_name' => 'Jan Novak', 'address_line1' => 'Nova 2', 'address_line2' => '',
            'postal_code' => '81101', 'city' => 'Bratislava', 'company_id' => '12345678', 'regon' => '', 'tax_id' => '',
            'vat_id'      => '', 'registry_note' => '', 'email' => 'firma@example.test', 'phone' => '', 'doc_language' => 'sk',
            'vat_regime'  => 'sk_non_vat', 'due_days' => '14', 'currency' => 'PLN',
        ]);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Entities.errorImmutable')), (string) $result->response()->getBody());
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM entity_versions')->getRow()->c);
    }

    public function testEndRejectsCountry(): void
    {
        $id = $this->createEntity('SK');
        $this->submit('/settings/entities/' . $id . '/end', ['active_to' => '2026-10-25', 'country' => 'PL'])->assertStatus(422);
        $this->assertNull($this->owner()->query('SELECT active_to FROM entities WHERE id = ?', [$id])->getRow()->active_to);
    }
}
