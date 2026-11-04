<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Nastavenie dokladov: lehota pregenerovania PDF faktury (D-13).
 *
 * @internal
 */
#[Group('feature')]
final class DocumentSettingsTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        $this->owner()->query('UPDATE document_settings SET regen_invoice_hours = 72, revision = 1 WHERE id = 1');
        $this->owner()->query("DELETE FROM audit_log WHERE entity_type = 'document_settings'");
    }

    private function body(TestResponse $r): string
    {
        return (string) $r->response()->getBody();
    }

    private function login(): void
    {
        $user = $this->createUser();
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    private function hours(): int
    {
        return (int) $this->owner()->query('SELECT regen_invoice_hours AS h FROM document_settings WHERE id = 1')->getRow()->h;
    }

    private function send(string $hours, string $revision = '1', bool $csrf = true, bool $htmx = false): TestResponse
    {
        $this->withSession();

        if ($htmx) {
            $this->withHeaders(['HX-Request' => 'true']);
        }

        return $this->call('post', '/settings/documents', ['regen_invoice_hours' => $hours, 'revision' => $revision] + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $result = $this->get('/settings/documents');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testFormShowsDefault(): void
    {
        $this->login();
        $result = $this->get('/settings/documents');
        $result->assertStatus(200);
        $html = $this->body($result);

        $this->assertMatchesRegularExpression('/<input[^>]*type="number"[^>]*min="0"[^>]*max="8760"[^>]*name="regen_invoice_hours"[^>]*value="72"/', $html);
        $this->assertStringContainsString('name="revision" value="1"', $html);
        $this->assertStringContainsString('data-submit-once', $html);
        $this->assertStringContainsString(csrf_token(), $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testPostWithoutCsrfIsForbidden(): void
    {
        $this->login();
        $this->send('24', '1', false)->assertStatus(403);
        $this->assertSame(72, $this->hours());
    }

    public function testSavePersistsAuditsAndRedirects(): void
    {
        $this->login();
        $result = $this->send('24');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/settings/documents', $result->response()->getHeaderLine('Location'));
        $this->assertSame(24, $this->hours());
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'document_settings' AND action = 'update'")->getRow()->c);
        $this->assertStringContainsString('Nastavenia boli uložené.', json_encode($_SESSION ?? [], JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testZeroIsAccepted(): void
    {
        $this->login();
        $this->send('0')->assertStatus(302);
        $this->assertSame(0, $this->hours());
    }

    public function testHtmxSuccessUsesHxLocation(): void
    {
        $this->login();
        $result = $this->send('24', '1', true, true);
        $result->assertStatus(200);
        $this->assertSame('/settings/documents', $result->response()->getHeaderLine('HX-Location'));
    }

    public function testInvalidValueGives422(): void
    {
        $this->login();
        $result = $this->send('9999');
        $result->assertStatus(422);
        $html = $this->body($result);
        $this->assertStringContainsString('Zadajte celé číslo od 0 do 8760.', $html);
        $this->assertStringContainsString('is-invalid', $html);
        $this->assertSame(72, $this->hours());
    }

    public function testStaleRevisionGives409(): void
    {
        $this->login();
        $this->send('24')->assertStatus(302);
        $result = $this->send('48');
        $result->assertStatus(409);
        $this->assertStringContainsString('Nastavenie medzitým zmenil niekto iný.', $this->body($result));
        $this->assertSame(24, $this->hours());
    }

    public function testMoreLinksToScreen(): void
    {
        $this->login();
        $this->assertStringContainsString('href="/settings/documents"', $this->body($this->get('/more')));
    }

    public function testAuditViewerShowsTranslatedEntity(): void
    {
        $this->login();
        $this->send('24')->assertStatus(302);
        $html = $this->body($this->get('/settings/audit'));
        $this->assertStringContainsString('Nastavenie dokladov', $html);
        $this->assertStringNotContainsString('>document_settings<', $html);
    }
}
