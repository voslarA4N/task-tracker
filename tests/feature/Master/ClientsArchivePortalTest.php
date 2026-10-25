<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class ClientsArchivePortalTest extends AuthTestCase
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
            'cc_emails'           => '',
            'hourly_rate'         => '27',
            'currency'            => 'EUR',
            'doc_language'        => '',
            'due_days'            => '',
            'billing_mode'        => 'domestic',
            'counts_toward_limit' => '1',
            'portal_name'         => '',
            'portal_url_template' => '',
        ];
    }

    /**
     * @param array<string, string> $data
     */
    private function submit(string $url, array $data = []): TestResponse
    {
        $this->withSession();

        return $this->call('post', $url, $data + [csrf_token() => csrf_hash()]);
    }

    public function testEditShowsArchiveCardAndModal(): void
    {
        $id   = $this->createClient();
        $html = $this->body($this->get('/settings/clients/' . $id . '/edit'));

        $this->assertStringContainsString(esc(lang('Clients.archiveButton')), $html);
        $this->assertStringContainsString('btn-outline-danger', $html);
        $this->assertStringContainsString('action="/settings/clients/' . $id . '/archive"', $html);
        $this->assertStringContainsString('name="portal_name"', $html);
        $this->assertMatchesRegularExpression('/type="url"[^>]*name="portal_url_template"|name="portal_url_template"[^>]*type="url"/', $html);
        $this->assertStringContainsString('hx-get="/settings/clients/portal-preview"', $html);
    }

    public function testArchiveAndFilter(): void
    {
        $id     = $this->createClient();
        $result = $this->submit('/settings/clients/' . $id . '/archive');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/settings/clients', $result->getRedirectUrl());

        $this->withSession();
        $active = $this->body($this->get('/settings/clients'));
        $this->assertStringContainsString(esc(lang('Clients.archivedFlash')), $active);
        $this->assertStringNotContainsString('All4Net', $active);

        $this->withSession();
        $archived = $this->body($this->get('/settings/clients?archived=1'));
        $this->assertStringContainsString('All4Net', $archived);
        $this->assertStringContainsString(esc(lang('Clients.badgeArchived')), $archived);
        $this->assertMatchesRegularExpression('~aria-current="page"[^>]*>\s*' . preg_quote(esc(lang('Clients.filterArchived')), '~') . '~', $archived);
    }

    public function testEmptyArchived(): void
    {
        $this->createClient();
        $html = $this->body($this->get('/settings/clients?archived=1'));
        $this->assertStringContainsString(esc(lang('Clients.emptyArchivedHeading')), $html);
    }

    public function testUnarchive(): void
    {
        $id = $this->createClient();
        $this->submit('/settings/clients/' . $id . '/archive');

        $this->withSession();
        $edit = $this->body($this->get('/settings/clients/' . $id . '/edit'));
        $this->assertStringContainsString(esc(lang('Clients.unarchiveButton')), $edit);

        $result = $this->submit('/settings/clients/' . $id . '/unarchive');
        $result->assertStatus(302);

        $this->withSession();
        $html = $this->body($this->get('/settings/clients'));
        $this->assertStringContainsString(esc(lang('Clients.unarchivedFlash')), $html);
        $this->assertStringContainsString('All4Net', $html);
    }

    public function testPortalPreviewIsTextOnly(): void
    {
        $result = $this->withHeaders(['HX-Request' => 'true'])
            ->get('/settings/clients/portal-preview?portal_url_template=' . rawurlencode('https://t.example/task/{id}'));
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertStringContainsString('https://t.example/task/123', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testPortalPreviewRejectsJavascript(): void
    {
        $result = $this->withHeaders(['HX-Request' => 'true'])
            ->get('/settings/clients/portal-preview?portal_url_template=' . rawurlencode('javascript:alert(1)//{id}'));
        $html = $this->body($result);

        $this->assertStringContainsString(esc(lang('Clients.errorPortalTemplate')), $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testSaveRejectsJavascriptTemplate(): void
    {
        $id     = $this->createClient();
        $result = $this->submit('/settings/clients/' . $id, $this->clientPost(['hourly_rate' => '99', 'portal_url_template' => 'javascript:alert(1)//{id}']));
        $result->assertStatus(422);
        $this->assertSame('27.00', $this->owner()->query('SELECT hourly_rate FROM clients WHERE id = ?', [$id])->getRow()->hourly_rate);
    }

    public function testSaveValidTemplate(): void
    {
        $id     = $this->createClient();
        $result = $this->submit('/settings/clients/' . $id, $this->clientPost(['portal_name' => 'Taskman', 'portal_url_template' => 'https://t.example/task/{id}']));
        $result->assertStatus(302);

        $row = $this->owner()->query('SELECT portal_name, portal_url_template FROM clients WHERE id = ?', [$id])->getRow();
        $this->assertSame('Taskman', $row->portal_name);
        $this->assertSame('https://t.example/task/{id}', $row->portal_url_template);
    }
}
