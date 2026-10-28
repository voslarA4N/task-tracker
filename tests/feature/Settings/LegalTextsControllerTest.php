<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Zakonne texty v nastaveniach (SET-01, D-13, D-14).
 *
 * @internal
 */
#[Group('feature')]
final class LegalTextsControllerTest extends AuthTestCase
{
    private int $textId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->textId = (int) $this->owner()->query("SELECT id FROM legal_texts WHERE country = 'SK' AND language = 'sk' AND key = 'vat_note'")->getRow()->id;
        $this->restore();
    }

    protected function tearDown(): void
    {
        $this->restore();
        parent::tearDown();
    }

    private function restore(): void
    {
        $this->owner()->query("DELETE FROM legal_texts WHERE revision = 1 AND ((country = 'SK' AND language = 'cs') OR billing_mode = 'non_eu')");
        $this->owner()->query("UPDATE legal_texts SET body = 'Nie som platiteľ DPH.', provisional = true, revision = 1 WHERE id = :id:", ['id' => $this->textId]);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    /**
     * @param array<string, string> $data
     */
    private function send(array $data, bool $csrf = true, bool $htmx = false): TestResponse
    {
        $this->withSession();

        if ($htmx) {
            $this->withHeaders(['HX-Request' => 'true']);
        }

        return $this->call('post', '/settings/legal-texts/' . $this->textId, $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $result = $this->get('/settings/legal-texts');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testListShowsGroupsBadgesAndCodes(): void
    {
        $user = $this->createUser();
        $this->enrollTotp($user);
        $this->loginAs($user);

        $html = $this->body($this->get('/settings/legal-texts'));

        $this->assertStringContainsString(esc(lang('LegalTexts.groupSkNonVat')), $html);
        $this->assertStringContainsString(esc(lang('LegalTexts.groupPlExempt1131')), $html);
        $this->assertStringContainsString(esc(lang('LegalTexts.badgeProvisional')), $html);
        $this->assertStringContainsString('ART_113_1', $html);
        $this->assertStringContainsString('/settings/legal-texts/' . $this->textId . '/edit', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testEditFormAndCsrfAndUpdate(): void
    {
        $user = $this->createUser();
        $this->enrollTotp($user);
        $this->loginAs($user);

        $html = $this->body($this->get('/settings/legal-texts/' . $this->textId . '/edit'));
        $this->assertStringContainsString('maxlength="2000"', $html);
        $this->assertStringContainsString('name="revision" value="1"', $html);

        $this->send(['body' => 'X', 'revision' => '1'], false)->assertStatus(403);
        $this->assertSame(1, (int) $this->owner()->query('SELECT revision FROM legal_texts WHERE id = :id:', ['id' => $this->textId])->getRow()->revision);

        $result = $this->send(['body' => 'Nie som platiteľom DPH.', 'confirmed' => '1', 'revision' => '1']);
        $result->assertStatus(302);
        $this->assertStringEndsWith('/settings/legal-texts', $result->response()->getHeaderLine('Location'));

        $row = $this->owner()->query('SELECT * FROM legal_texts WHERE id = :id:', ['id' => $this->textId])->getRowArray();
        $this->assertSame(2, (int) $row['revision']);
        $this->assertSame('Nie som platiteľom DPH.', $row['body']);
        $this->assertContains($row['provisional'], [false, 'f', '0', 0]);
    }

    public function testHtmxUpdateRedirectsViaHeader(): void
    {
        $user = $this->createUser();
        $this->enrollTotp($user);
        $this->loginAs($user);

        $result = $this->send(['body' => 'Nové znenie.', 'revision' => '1'], true, true);
        $result->assertStatus(200);
        $this->assertSame('/settings/legal-texts', $result->response()->getHeaderLine('HX-Location'));
    }

    public function testEmptyBodyIs422AndStaleRevisionIs409(): void
    {
        $user = $this->createUser();
        $this->enrollTotp($user);
        $this->loginAs($user);

        $result = $this->send(['body' => '   ', 'revision' => '1']);
        $result->assertStatus(422);
        $this->assertStringContainsString('is-invalid', $this->body($result));

        $this->send(['body' => 'Prvá', 'revision' => '1'])->assertStatus(302);

        $stale = $this->send(['body' => 'Druhá', 'revision' => '1']);
        $stale->assertStatus(409);
        $this->assertStringContainsString(esc(lang('LegalTexts.errorStale')), $this->body($stale));
        $this->assertSame('Prvá', $this->owner()->query('SELECT body FROM legal_texts WHERE id = :id:', ['id' => $this->textId])->getRow()->body);
    }

    public function testStaleFormCarriesCurrentRevisionAndCanBeResaved(): void
    {
        $this->loginOwner();
        $this->send(['body' => 'Prvá', 'revision' => '1'])->assertStatus(302);

        $stale = $this->body($this->send(['body' => 'Moja úprava', 'revision' => '1']));
        $this->assertStringContainsString('name="revision" value="2"', $stale);
        $this->assertStringContainsString('>Moja úprava</textarea>', $stale);
        $this->assertStringContainsString('id="lt-current-body">Prvá</div>', $stale);

        $this->send(['body' => 'Moja úprava', 'revision' => '2'])->assertStatus(302);
        $this->assertSame('Moja úprava', $this->owner()->query('SELECT body FROM legal_texts WHERE id = :id:', ['id' => $this->textId])->getRow()->body);
    }

    private function loginOwner(): void
    {
        $user = $this->createUser();
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    /**
     * @param array<string, string> $data
     */
    private function create(array $data, bool $csrf = true): TestResponse
    {
        $this->withSession();

        return $this->call('post', '/settings/legal-texts', $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    public function testListShowsFallbackAndMissingRows(): void
    {
        $this->loginOwner();
        $html = $this->body($this->get('/settings/legal-texts'));

        $this->assertStringContainsString('lt-effective', $html);
        $this->assertStringContainsString(esc(lang('LegalTexts.badgeFallback')), $html);
        $this->assertStringContainsString(esc('/settings/legal-texts/new?', 'attr'), $html);
        $this->assertStringContainsString(esc(lang('LegalTexts.effectiveLine', ['cs', lang('LegalTexts.modeGeneral'), lang('LegalTexts.keyVatNote'), 'en', lang('LegalTexts.modeGeneral'), 1])), $html);
    }

    public function testNewFormPrefillsFromQueryAndIgnoresInvalid(): void
    {
        $this->loginOwner();
        $html = $this->body($this->get('/settings/legal-texts/new?country=SK&vat_regime=sk_non_vat&language=cs&key=vat_note&billing_mode=non_eu'));

        $this->assertStringContainsString('value="' . esc('SK|sk_non_vat', 'attr') . '" selected', $html);
        $this->assertStringContainsString('value="cs" selected', $html);
        $this->assertStringContainsString('value="non_eu" selected', $html);
        $this->assertStringContainsString('csrf', strtolower($html));

        $bad = $this->body($this->get('/settings/legal-texts/new?country=%22%3E%3Cscript%3E&language=xx'));
        $this->assertStringNotContainsString('<script>', $bad);
        $this->assertStringNotContainsString('value="xx" selected', $bad);
    }

    public function testCreateValidRedirectsAndInserts(): void
    {
        $this->loginOwner();
        $result = $this->create(['profile' => 'SK|sk_non_vat', 'key' => 'vat_note', 'language' => 'cs', 'billing_mode' => '', 'body' => 'Nejsem plátce DPH.']);

        $result->assertStatus(302);
        $this->assertStringEndsWith('/settings/legal-texts', $result->response()->getHeaderLine('Location'));
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM legal_texts WHERE country = 'SK' AND language = 'cs'")->getRow()->c);
    }

    public function testCreateDuplicateAndInvalidKeyAre422(): void
    {
        $this->loginOwner();
        $dup = $this->create(['profile' => 'SK|sk_non_vat', 'key' => 'vat_note', 'language' => 'sk', 'billing_mode' => '', 'body' => 'Iné']);
        $dup->assertStatus(422);
        $this->assertStringContainsString(esc(lang('LegalTexts.errorExists')), $this->body($dup));

        $this->create(['profile' => 'SK|sk_non_vat', 'key' => 'footer', 'language' => 'cs', 'billing_mode' => '', 'body' => 'x'])->assertStatus(422);
    }

    public function testCreateWithoutCsrfIs403(): void
    {
        $this->loginOwner();
        $this->create(['profile' => 'SK|sk_non_vat', 'key' => 'vat_note', 'language' => 'cs', 'billing_mode' => '', 'body' => 'x'], false)->assertStatus(403);
    }
}
