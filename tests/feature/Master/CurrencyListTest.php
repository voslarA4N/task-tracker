<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class CurrencyListTest extends AuthTestCase
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
     * @return array<string, string>
     */
    private function payload(string $currency): array
    {
        return [
            'name' => 'All4Net', 'address_line1' => 'Hlavna 1', 'address_line2' => '', 'postal_code' => '81101', 'city' => 'Bratislava',
            'country' => 'SK', 'identifier_type' => 'local_id', 'identifier_value' => '12345678', 'tax_id' => '', 'vat_id' => '',
            'invoice_email' => 'fakturacia@example.test', 'cc_emails' => '', 'hourly_rate' => '30', 'currency' => $currency,
            'doc_language' => '', 'due_days' => '', 'billing_mode' => 'domestic', 'counts_toward_limit' => '1',
        ];
    }

    public function testFormListsCurrenciesInOrderAndHidesInactive(): void
    {
        $html = $this->body($this->get('/settings/clients/new'));
        $this->assertMatchesRegularExpression('~<option value="EUR".*<option value="PLN".*<option value="CZK"~s', $html);

        $owner = Database::connect('tests_owner', false);
        $owner->query("UPDATE currencies SET active = false WHERE code = 'CZK'");

        try {
            $html = $this->body($this->get('/settings/clients/new'));
            $this->assertStringNotContainsString('value="CZK"', $html);
        } finally {
            $owner->query("UPDATE currencies SET active = true WHERE code = 'CZK'");
        }
    }

    public function testNewCurrencyAcceptedAndUnknownRejected(): void
    {
        $owner = Database::connect('tests_owner', false);
        $owner->query("INSERT INTO currencies (code, decimals, active, sort_order) VALUES ('HUF', 2, true, 4)");

        try {
            $this->assertStringContainsString('value="HUF"', $this->body($this->get('/settings/clients/new')));
            $this->withSession();
            $ok = $this->call('post', '/settings/clients', $this->payload('HUF') + [csrf_token() => csrf_hash()]);
            $this->assertTrue($ok->isRedirect());
        } finally {
            $this->resetMasterTables();
            $owner->query("DELETE FROM currencies WHERE code = 'HUF'");
        }

        $this->withSession();
        $bad = $this->call('post', '/settings/clients', $this->payload('USD') + [csrf_token() => csrf_hash()]);
        $bad->assertStatus(422);
        $this->assertStringContainsString('f-currency-error', $this->body($bad));
    }
}
