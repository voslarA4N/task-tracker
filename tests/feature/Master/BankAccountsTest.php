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
final class BankAccountsTest extends AuthTestCase
{
    use MasterFixtures;

    private const SK_IBAN = 'SK3112000000198742637541';
    private const PL_IBAN = 'PL61109010140000071219812874';

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
     * @param array<string, string> $data
     */
    private function send(string $path, array $data = []): TestResponse
    {
        $this->withSession();

        return $this->call('post', $path, $data + [csrf_token() => csrf_hash()]);
    }

    /**
     * @param array<string, string> $data
     */
    private function addAccount(int $entityId, array $data): TestResponse
    {
        return $this->send('/settings/entities/' . $entityId . '/bank-accounts', $data);
    }

    private function accountId(string $iban): int
    {
        return (int) $this->owner()->query('SELECT id FROM entity_bank_accounts WHERE iban = ?', [$iban])->getRow()->id;
    }

    public function testDetailWithoutAccountsShowsEmptyState(): void
    {
        $id   = $this->createEntity('SK');
        $html = $this->body($this->get('/settings/entities/' . $id));

        $this->assertStringContainsString(esc(lang('Entities.bankEmptyHeading')), $html);
        $this->assertStringContainsString('bank-accounts/new', $html);
    }

    public function testNewFormAttributesAndLockedDefault(): void
    {
        $id   = $this->createEntity('SK');
        $html = $this->body($this->get('/settings/entities/' . $id . '/bank-accounts/new'));

        $this->assertStringContainsString('autocomplete="off"', $html);
        $this->assertStringContainsString('spellcheck="false"', $html);
        $this->assertStringContainsString('autocapitalize="characters"', $html);
        $this->assertMatchesRegularExpression('/name="is_default"[^>]*checked[^>]*disabled|name="is_default"[^>]*disabled[^>]*checked/', $html);
    }

    public function testCreateAccountNormalizesIbanAndShowsOnDetail(): void
    {
        $id = $this->createEntity('SK');
        $this->addAccount($id, ['label' => 'Hlavny EUR', 'iban' => 'sk31 1200 0000 1987 4263 7541', 'swift' => '', 'currency' => 'EUR'])->assertStatus(302);

        $this->assertSame(self::SK_IBAN, $this->owner()->query('SELECT iban FROM entity_bank_accounts')->getRow()->iban);

        $this->withSession();
        $html = $this->body($this->get('/settings/entities/' . $id));
        $this->assertStringContainsString(esc(lang('Entities.bankSaved')), $html);
        $this->assertStringContainsString('SK31 1200 0000 1987 4263 7541', $html);
        $this->assertStringContainsString(esc(lang('Entities.badgeDefault')), $html);
    }

    public function testInvalidIbanIs422(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->addAccount($id, ['label' => 'X', 'iban' => 'SK3112000000198742637542', 'swift' => '', 'currency' => 'EUR']);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Entities.errorIban')), $this->body($result));
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM entity_bank_accounts')->getRow()->c);
    }

    public function testInvalidSwiftIs422(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->addAccount($id, ['label' => 'X', 'iban' => self::SK_IBAN, 'swift' => 'ABC', 'currency' => 'EUR']);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Entities.errorSwift')), $this->body($result));
    }

    public function testEditFormHasFixedCurrency(): void
    {
        $id = $this->createEntity('SK');
        $this->addAccount($id, ['label' => 'Hlavny', 'iban' => self::SK_IBAN, 'swift' => '', 'currency' => 'EUR'])->assertStatus(302);
        $acc  = $this->accountId(self::SK_IBAN);
        $html = $this->body($this->get('/settings/entities/' . $id . '/bank-accounts/' . $acc . '/edit'));

        $this->assertStringContainsString('master-fixed', $html);
        $this->assertStringNotContainsString('name="currency"', $html);
        $this->assertStringContainsString('name="iban"', $html);
    }

    public function testUpdateKeepsCurrencyEvenWhenForged(): void
    {
        $id = $this->createEntity('SK');
        $this->addAccount($id, ['label' => 'Hlavny', 'iban' => self::SK_IBAN, 'swift' => '', 'currency' => 'EUR'])->assertStatus(302);
        $acc = $this->accountId(self::SK_IBAN);

        $this->send('/settings/entities/' . $id . '/bank-accounts/' . $acc, ['label' => 'Premenovany', 'iban' => self::SK_IBAN, 'swift' => '', 'currency' => 'PLN'])->assertStatus(302);

        $row = $this->owner()->query('SELECT label, currency FROM entity_bank_accounts WHERE id = ?', [$acc])->getRow();
        $this->assertSame('Premenovany', $row->label);
        $this->assertSame('EUR', trim((string) $row->currency));
    }

    public function testSetDefaultAndArchiveFlow(): void
    {
        $id = $this->createEntity('PL');
        $this->addAccount($id, ['label' => 'EUR', 'iban' => self::SK_IBAN, 'swift' => '', 'currency' => 'EUR'])->assertStatus(302);
        $this->addAccount($id, ['label' => 'PLN', 'iban' => self::PL_IBAN, 'swift' => '', 'currency' => 'PLN'])->assertStatus(302);
        $a = $this->accountId(self::SK_IBAN);
        $b = $this->accountId(self::PL_IBAN);

        $this->send('/settings/entities/' . $id . '/bank-accounts/' . $b . '/default')->assertStatus(302);
        $this->withSession();
        $this->assertStringContainsString(esc(lang('Entities.bankDefaultChanged')), $this->body($this->get('/settings/entities/' . $id)));
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM entity_bank_accounts WHERE is_default')->getRow()->c);

        $this->send('/settings/entities/' . $id . '/bank-accounts/' . $a . '/archive')->assertStatus(302);
        $this->withSession();
        $html = $this->body($this->get('/settings/entities/' . $id));
        $this->assertStringContainsString(esc(lang('Entities.bankArchived')), $html);
        $this->assertStringNotContainsString('SK31 1200 0000 1987 4263 7541', $html);
        $this->assertStringContainsString(esc(lang('Entities.showArchived', [1])), $html);
    }

    public function testArchivedFragmentViaHtmx(): void
    {
        $id = $this->createEntity('SK');
        $this->addAccount($id, ['label' => 'Hlavny', 'iban' => self::SK_IBAN, 'swift' => '', 'currency' => 'EUR'])->assertStatus(302);
        $this->send('/settings/entities/' . $id . '/bank-accounts/' . $this->accountId(self::SK_IBAN) . '/archive')->assertStatus(302);

        $this->withSession();
        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/settings/entities/' . $id . '/bank-accounts/archived');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString('SK31 1200 0000 1987 4263 7541', $html);
        $this->assertStringNotContainsString('<html', $html);
    }

    public function testAccountOfOtherEntityIs404(): void
    {
        $id    = $this->createEntity('SK');
        $other = $this->createEntity('PL');
        $this->addAccount($id, ['label' => 'Hlavny', 'iban' => self::SK_IBAN, 'swift' => '', 'currency' => 'EUR'])->assertStatus(302);
        $acc = $this->accountId(self::SK_IBAN);

        // FeatureTestTrait prepusta PageNotFoundException, handler 404 renderuje az produkcny front controller.
        foreach ([
            fn () => $this->get('/settings/entities/' . $other . '/bank-accounts/' . $acc . '/edit'),
            fn () => $this->send('/settings/entities/' . $other . '/bank-accounts/' . $acc . '/archive'),
            fn () => $this->send('/settings/entities/' . $other . '/bank-accounts/' . $acc . '/default'),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected PageNotFoundException (404)');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull($this->owner()->query('SELECT archived_at FROM entity_bank_accounts WHERE id = ?', [$acc])->getRow()->archived_at);
    }
}
