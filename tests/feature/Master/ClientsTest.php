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
final class ClientsTest extends AuthTestCase
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
    private function clientPost(array $over = []): array
    {
        return $over + [
            'name'                => 'All4Net',
            'address_line1'       => 'Hlavna 1',
            'address_line2'       => '',
            'postal_code'         => '81101',
            'city'                => 'Bratislava',
            'country'             => 'SK',
            'identifier_type'     => 'local_id',
            'identifier_value'    => '12345678',
            'tax_id'              => '',
            'vat_id'              => '',
            'invoice_email'       => 'fakturacia@all4net.example',
            'cc_emails'           => "a@x.sk\nb@x.sk",
            'hourly_rate'         => '27',
            'currency'            => 'EUR',
            'doc_language'        => '',
            'due_days'            => '',
            'billing_mode'        => 'domestic',
            'counts_toward_limit' => '1',
        ];
    }

    /**
     * @param array<string, string> $data
     */
    private function submit(string $url, array $data): TestResponse
    {
        $this->withSession();

        return $this->call('post', $url, $data + [csrf_token() => csrf_hash()]);
    }

    public function testEmptyList(): void
    {
        $result = $this->get('/settings/clients');
        $result->assertStatus(200);
        $this->assertStringContainsString(esc(lang('Clients.emptyHeading')), $this->body($result));
    }

    public function testMoreLinksToClientsAfterEntities(): void
    {
        $html = $this->body($this->get('/more'));
        $this->assertMatchesRegularExpression('~href="/settings/entities".*href="/settings/clients"~s', $html);
    }

    public function testNewFormDefaults(): void
    {
        $result = $this->get('/settings/clients/new');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertMatchesRegularExpression('/name="counts_toward_limit"[^>]*checked/', $html);
        $this->assertStringContainsString('<fieldset', $html);
        $this->assertStringContainsString('<legend', $html);
        $this->assertStringContainsString('x-data="clientForm"', $html);
    }

    public function testCreateRedirectsAndListShowsRate(): void
    {
        $result = $this->submit('/settings/clients', $this->clientPost());
        $result->assertStatus(302);
        $this->assertStringEndsWith('/settings/clients', $result->getRedirectUrl());

        $this->withSession();
        $html = $this->body($this->get('/settings/clients'));
        $this->assertStringContainsString('All4Net', $html);
        $this->assertStringContainsString('27,00', $html);
        $this->assertStringContainsString(esc(lang('Clients.saved')), $html);
    }

    public function testEuB2bWithoutVatIs422(): void
    {
        $result = $this->submit('/settings/clients', $this->clientPost(['billing_mode' => 'eu_b2b']));
        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Clients.errorEuB2bVat')), $this->body($result));
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM clients')->getRow()->c);
    }

    public function testEditPrefilledAndUpdate(): void
    {
        $id = $this->createClient(['cc_emails' => ['a@x.sk', 'b@x.sk']]);

        $edit = $this->get('/settings/clients/' . $id . '/edit');
        $edit->assertStatus(200);
        $html = $this->body($edit);
        $this->assertStringContainsString('value="All4Net"', $html);
        $this->assertStringContainsString("a@x.sk\nb@x.sk", $html);
        $this->assertStringNotContainsString('delete', strtolower($html));

        $result = $this->submit('/settings/clients/' . $id, $this->clientPost(['hourly_rate' => '30,5']));
        $result->assertStatus(302);
        $this->assertSame('30.50', $this->owner()->query('SELECT hourly_rate FROM clients WHERE id = ?', [$id])->getRow()->hourly_rate);

        $this->withSession();
        $this->assertStringContainsString(esc(lang('Clients.saved')), $this->body($this->get('/settings/clients')));
    }

    public function testUnknownClientIs404(): void
    {
        try {
            $this->get('/settings/clients/999/edit');
            $this->fail('Expected PageNotFoundException');
        } catch (PageNotFoundException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }
}
