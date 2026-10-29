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

    private string $originalFrom    = '';
    private string $originalDomains = '';

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
        $this->originalFrom        = config('Email')->fromEmail;
        config('Email')->fromEmail = 'noreply@vostack.dev';
        // CI .env (z .env.example) ma aj work-tracker.loc, test chce presne jednu domenu
        $this->originalDomains              = config('Email')->allowedFromDomains;
        config('Email')->allowedFromDomains = 'vostack.dev';
        $user                               = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        config('Email')->fromEmail          = $this->originalFrom;
        config('Email')->allowedFromDomains = $this->originalDomains;
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
        // Predvolene hodnoty su len placeholder, nie value (CR-01, D-27)
        $this->assertStringContainsString('placeholder="' . esc('noreply@vostack.dev', 'attr') . '"', $html);
        $this->assertStringContainsString('placeholder="' . esc('firma@example.test', 'attr') . '"', $html);
        $this->assertStringContainsString('placeholder="' . esc('Jan Novak', 'attr') . '"', $html);
        $this->assertStringNotContainsString('value="' . esc('noreply@vostack.dev', 'attr') . '"', $html);
        $this->assertStringNotContainsString('value="' . esc('Jan Novak', 'attr') . '"', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="mail_bcc_none"(?![^>]*checked)[^>]*>/', $html);
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

    /**
     * Hodnoty formulara z HTML (input value a textarea), ako by ich poslal prehliadac bez zmeny.
     *
     * @return array<string, string>
     */
    private function formValues(string $html): array
    {
        $out = [];
        preg_match_all('/<input[^>]*\bname="([a-z_]+)"[^>]*>/', $html, $inputs, PREG_SET_ORDER);

        foreach ($inputs as [$tag, $name]) {
            if (str_contains($tag, 'type="checkbox"') && ! str_contains($tag, ' checked')) {
                continue;
            }

            if (preg_match('/\bvalue="([^"]*)"/', $tag, $m) === 1 && $name !== csrf_token()) {
                $out[$name] = html_entity_decode($m[1], ENT_QUOTES);
            }
        }

        preg_match_all('/<textarea[^>]*\bname="([a-z_]+)"[^>]*>(.*?)<\/textarea>/s', $html, $areas, PREG_SET_ORDER);

        foreach ($areas as [, $name, $text]) {
            $out[$name] = html_entity_decode($text, ENT_QUOTES);
        }

        return $out;
    }

    public function testPostingEditFormUnchangedKeepsSenderColumnsNull(): void
    {
        $id   = $this->createEntity('SK');
        $form = $this->formValues($this->body($this->get('/settings/entities/' . $id . '/edit')));

        $this->assertArrayHasKey('mail_from_email', $form);
        $this->assertArrayNotHasKey('mail_bcc_none', $form);

        $form['name_internal'] = 'Iny nazov';
        $this->update($id, $form)->assertStatus(302);

        $row = $this->owner()->query('SELECT name_internal, mail_from_name, mail_from_email, mail_reply_to, mail_bcc FROM entities WHERE id = ?', [$id])->getRow();
        $this->assertSame('Iny nazov', $row->name_internal);
        $this->assertNull($row->mail_from_name);
        $this->assertNull($row->mail_from_email);
        $this->assertNull($row->mail_reply_to);
        $this->assertNull($row->mail_bcc);
    }

    public function testUnchangedFormSavesEvenWhenConfigFromIsOutsideAllowedDomains(): void
    {
        $id                        = $this->createEntity('SK');
        config('Email')->fromEmail = 'noreply@stage.vostack.dev';
        $form                      = $this->formValues($this->body($this->get('/settings/entities/' . $id . '/edit')));

        $this->update($id, $form)->assertStatus(302);
        $this->assertNull($this->owner()->query('SELECT mail_from_email FROM entities WHERE id = ?', [$id])->getRow()->mail_from_email);
    }

    public function testNoBccCheckboxStoresEmptyListAndRoundTrips(): void
    {
        $id = $this->createEntity('SK');
        $this->update($id, ['mail_from_name' => '', 'mail_from_email' => '', 'mail_reply_to' => '', 'mail_bcc' => '', 'mail_bcc_none' => '1'])->assertStatus(302);
        $this->assertSame('[]', $this->owner()->query('SELECT mail_bcc::text AS b FROM entities WHERE id = ?', [$id])->getRow()->b);

        $this->withSession();
        $form = $this->formValues($this->body($this->get('/settings/entities/' . $id . '/edit')));
        $this->assertSame('1', $form['mail_bcc_none'] ?? null);

        // Odskrtnutie s prazdnym polom vrati predvolene BCC (NULL)
        unset($form['mail_bcc_none']);
        $this->update($id, $form)->assertStatus(302);
        $this->assertNull($this->owner()->query('SELECT mail_bcc FROM entities WHERE id = ?', [$id])->getRow()->mail_bcc);
    }

    public function testNoBccWithAddressesIs422(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->update($id, ['mail_from_name' => '', 'mail_from_email' => '', 'mail_reply_to' => '', 'mail_bcc' => 'a@x.sk', 'mail_bcc_none' => '1']);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Entities.errorSenderBccConflict')), $this->body($result));
        $this->assertNull($this->owner()->query('SELECT mail_bcc FROM entities WHERE id = ?', [$id])->getRow()->mail_bcc);
    }
}
