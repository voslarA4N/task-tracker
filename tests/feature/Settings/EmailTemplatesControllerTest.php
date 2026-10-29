<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Sablony e-mailov v nastaveniach (MAIL-04, D-14 az D-17).
 *
 * @internal
 */
#[Group('feature')]
final class EmailTemplatesControllerTest extends AuthTestCase
{
    private int $id = 0;

    /**
     * @var list<array<string, mixed>>
     */
    private array $original = [];

    private bool $loggedIn = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = $this->owner()->query('SELECT id, subject, body, revision, updated_at FROM email_templates')->getResultArray();
        $this->id       = (int) $this->owner()->query("SELECT id FROM email_templates WHERE key = 'invoice' AND language = 'sk'")->getRow()->id;
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $r) {
            $this->owner()->query(
                'UPDATE email_templates SET subject = :s:, body = :b:, revision = :r:, updated_at = :u: WHERE id = :id:',
                ['s' => $r['subject'], 'b' => $r['body'], 'r' => $r['revision'], 'u' => $r['updated_at'], 'id' => $r['id']],
            );
        }
        $this->owner()->query("DELETE FROM audit_log WHERE entity_type = 'email_template'");
        parent::tearDown();
    }

    private function login(): void
    {
        if ($this->loggedIn) {
            $this->withSession();

            return;
        }
        $this->loggedIn = true;
        $user           = $this->createUser();
        $this->enrollTotp($user);
        $this->loginAs($user);
        $this->withSession();
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function revision(): int
    {
        return (int) $this->owner()->query('SELECT revision FROM email_templates WHERE id = :id:', ['id' => $this->id])->getRow()->revision;
    }

    /**
     * @param array<string, string> $data
     */
    private function send(array $data, bool $csrf = true, bool $htmx = false, string $suffix = ''): TestResponse
    {
        $this->login();

        if ($htmx) {
            $this->withHeaders(['HX-Request' => 'true']);
        }

        return $this->call('post', '/settings/email-templates/' . $this->id . $suffix, $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $result = $this->get('/settings/email-templates');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testIndexListsGroupsAndLanguages(): void
    {
        $this->login();
        $html = $this->body($this->call('get', '/settings/email-templates'));
        $this->assertStringContainsString('Faktúra', $html);
        $this->assertStringContainsString('Upomienka', $html);
        $this->assertSame(8, substr_count($html, 'class="lt-row'));
        $this->assertLessThan(strpos($html, 'Poľština'), strpos($html, 'Slovenčina'));
        $this->assertLessThan(strpos($html, 'Čeština'), strpos($html, 'Poľština'));
        $this->assertLessThan(strpos($html, 'Angličtina'), strpos($html, 'Čeština'));
        $this->assertStringContainsString('revízia 1', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertDoesNotMatchRegularExpression('/delete|zmaza/i', $html);
    }

    public function testEditShowsFieldsAndTwelveVariables(): void
    {
        $this->login();
        $html = $this->body($this->call('get', '/settings/email-templates/' . $this->id . '/edit'));
        $this->assertStringContainsString('name="subject"', $html);
        $this->assertStringContainsString('name="body"', $html);
        $this->assertSame(12, substr_count($html, 'data-var="'));
        $this->assertStringContainsString('name="revision" value="1"', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $html);
        $this->assertDoesNotMatchRegularExpression('/delete|zmaza/i', $html);
    }

    public function testEditUnknownIs404(): void
    {
        $this->login();
        $this->expectException(PageNotFoundException::class);
        $this->call('get', '/settings/email-templates/999999/edit');
    }

    public function testValidUpdateRedirectsAndBumpsRevision(): void
    {
        $result = $this->send(['subject' => 'Faktúra {{number}}', 'body' => 'Dobrý deň {{iban}}', 'revision' => '1']);
        $result->assertRedirectTo('/settings/email-templates');
        $this->assertSame(2, $this->revision());
        $this->assertSame('Šablóna bola uložená (revízia 2).', session()->getFlashdata('app_notice')['message'] ?? null);
    }

    public function testHtmxUpdateReturnsHxLocation(): void
    {
        $result = $this->send(['subject' => 'Faktúra {{number}}', 'body' => 'Telo', 'revision' => '1'], true, true);
        $result->assertStatus(200);
        $this->assertSame('/settings/email-templates', $result->response()->getHeaderLine('HX-Location'));
    }

    public function testUnknownPlaceholderIs422(): void
    {
        $result = $this->send(['subject' => 'Faktúra', 'body' => 'Ahoj {{foo}}', 'revision' => '1']);
        $result->assertStatus(422);
        $this->assertStringContainsString('{{foo}}', $this->body($result));
        $this->assertSame(1, $this->revision());
    }

    public function testEmptySubjectIs422(): void
    {
        $result = $this->send(['subject' => '  ', 'body' => 'Telo', 'revision' => '1']);
        $result->assertStatus(422);
        $this->assertStringContainsString('is-invalid', $this->body($result));
        $this->assertSame(1, $this->revision());
    }

    public function testStaleRevisionIs409(): void
    {
        $this->send(['subject' => 'A {{number}}', 'body' => 'B', 'revision' => '1']);
        $result = $this->send(['subject' => 'C {{number}}', 'body' => 'D', 'revision' => '1']);
        $result->assertStatus(409);
        $this->assertStringContainsString('Šablónu medzitým niekto upravil', $this->body($result));
        $this->assertSame(2, $this->revision());
    }

    public function testPostWithoutCsrfIsForbidden(): void
    {
        $this->send(['subject' => 'A', 'body' => 'B', 'revision' => '1'], false)->assertStatus(403);
        $this->assertSame(1, $this->revision());
    }

    public function testPreviewRendersSampleWithoutSaving(): void
    {
        $result = $this->send(['subject' => 'Faktúra č. {{number}}', 'body' => 'Suma {{total}}', 'revision' => '1'], true, true, '/preview');
        $result->assertStatus(200);
        $html = $this->body($result);
        $this->assertStringContainsString('202610001', $html);
        $this->assertStringContainsString('Vzorové hodnoty', $html);
        $this->assertSame(1, $this->revision());
    }

    public function testPreviewEscapesAndRejectsUnknown(): void
    {
        $html = $this->body($this->send(['subject' => '<b>x</b>', 'body' => '<script>1</script>', 'revision' => '1'], true, true, '/preview'));
        $this->assertStringNotContainsString('<script>', $html);

        $html = $this->body($this->send(['subject' => 'a', 'body' => '{{zzz}}', 'revision' => '1'], true, true, '/preview'));
        $this->assertStringContainsString('{{zzz}}', $html);
        $this->assertStringContainsString('alert-danger', $html);
    }
}
