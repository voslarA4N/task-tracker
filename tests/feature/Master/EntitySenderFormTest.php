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
final class EntitySenderFormTest extends AuthTestCase
{
    use MasterFixtures;

    private string $originalFrom = '';

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
        $this->originalFrom        = config('Email')->fromEmail;
        config('Email')->fromEmail = 'noreply@vostack.dev';
        $user                      = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        config('Email')->fromEmail = $this->originalFrom;
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
    private function update(int $id, array $data, bool $csrf = true): TestResponse
    {
        $this->withSession();
        $base = ['name_internal' => 'Moja firma SK', 'active_from' => '2026-01-01', 'active_to' => ''];

        return $this->call('post', '/settings/entities/' . $id, $data + $base + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    public function testEditShowsCardWithResolvedDefaults(): void
    {
        $id   = $this->createEntity('SK');
        $html = $this->body($this->get('/settings/entities/' . $id . '/edit'));

        $this->assertStringContainsString('sender-card', $html);
        $this->assertStringContainsString('value="' . esc('noreply@vostack.dev', 'attr') . '"', $html);
        $this->assertStringContainsString('value="' . esc('firma@example.test', 'attr') . '"', $html);
        $this->assertStringContainsString('value="' . esc('Jan Novak', 'attr') . '"', $html);
        $this->assertStringContainsString('vostack.dev', $html);
        $this->assertStringNotContainsString(' style=', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testDisallowedDomainIs422(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->update($id, ['mail_from_email' => 'x@gmail.com', 'mail_from_name' => '', 'mail_reply_to' => '', 'mail_bcc' => '']);

        $result->assertStatus(422);
        $html = $this->body($result);
        $this->assertStringContainsString('is-invalid', $html);
        $this->assertStringContainsString(esc(lang('Entities.errorSenderDomain', ['vostack.dev'])), $html);
        $this->assertNull($this->owner()->query('SELECT mail_from_email FROM entities WHERE id = ?', [$id])->getRow()->mail_from_email);
    }

    public function testValidSaveRedirectsAndPersists(): void
    {
        $id = $this->createEntity('SK');
        $this->update($id, ['mail_from_name' => 'All4Net', 'mail_from_email' => 'faktury@vostack.dev', 'mail_reply_to' => 'ucto@all4net.sk', 'mail_bcc' => "a@x.sk\nb@x.sk"])
            ->assertStatus(302);

        $row = $this->owner()->query('SELECT * FROM entities WHERE id = ?', [$id])->getRow();
        $this->assertSame('faktury@vostack.dev', $row->mail_from_email);
        $this->assertSame('ucto@all4net.sk', $row->mail_reply_to);
        $this->assertSame(['a@x.sk', 'b@x.sk'], json_decode((string) $row->mail_bcc, true));

        $this->withSession();
        $this->assertStringContainsString(esc(lang('Entities.senderSaved')), $this->body($this->get('/settings/entities/' . $id)));
    }

    public function testPostWithoutCsrfIs403(): void
    {
        $id = $this->createEntity('SK');

        $this->update($id, ['mail_from_email' => 'faktury@vostack.dev'], false)->assertStatus(403);
    }

    public function testPostWithoutSenderFieldsKeepsSettings(): void
    {
        $id = $this->createEntity('SK');
        $this->update($id, ['mail_from_email' => 'faktury@vostack.dev', 'mail_from_name' => '', 'mail_reply_to' => '', 'mail_bcc' => ''])->assertStatus(302);
        $this->update($id, [])->assertStatus(302);

        $this->assertSame('faktury@vostack.dev', $this->owner()->query('SELECT mail_from_email FROM entities WHERE id = ?', [$id])->getRow()->mail_from_email);
    }
}
