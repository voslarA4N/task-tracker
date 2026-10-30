<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\FxFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * Nastavenia kurzu (CUR-05, D-05).
 *
 * @internal
 */
#[Group('feature')]
final class FxSettingsTest extends AuthTestCase
{
    use FxFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreFxSettings();
    }

    protected function tearDown(): void
    {
        $this->restoreFxSettings();
        parent::tearDown();
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

    /**
     * @param array<string, mixed> $override
     */
    private function send(array $override = [], bool $csrf = true, bool $htmx = false): TestResponse
    {
        $this->withSession();

        if ($htmx) {
            $this->withHeaders(['HX-Request' => 'true']);
        }

        $data = [
            'SK' => ['source' => 'ECB', 'date_basis' => 'delivery_date', 'day_rule' => 'previous_business_day', 'revision' => '1'],
            'PL' => ['source' => 'NBP', 'date_basis' => 'delivery_date', 'day_rule' => 'previous_business_day', 'revision' => '1'],
            'CZ' => ['source' => 'CNB', 'date_basis' => 'delivery_date', 'day_rule' => 'on_or_before', 'revision' => '1'],
        ];

        foreach ($override as $path => $value) {
            [$c, $f]    = explode('.', $path);
            $data[$c][$f] = $value;
        }

        return $this->call('post', '/settings/fx', $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $result = $this->get('/settings/fx');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testFormShowsDefaults(): void
    {
        $this->login();
        $html = $this->body($this->get('/settings/fx'));

        $this->assertStringContainsString('Kurz mien', $html);
        $this->assertSame(3, substr_count($html, '<fieldset'));
        $this->assertMatchesRegularExpression('/<select[^>]*name="PL\[source\]".*?<option value="NBP" selected>/s', $html);
        $this->assertMatchesRegularExpression('/<select[^>]*name="CZ\[day_rule\]".*?<option value="on_or_before" selected>/s', $html);
        $this->assertStringContainsString('name="PL[revision]" value="1"', $html);
        $this->assertStringContainsString('Pravidlá sú predbežné. Overte ich s účtovníčkou.', $html);
        $this->assertStringContainsString('Predvolené: PL NBP', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testSavePersistsAuditsAndRedirects(): void
    {
        $this->login();
        $this->send([], false)->assertStatus(403);

        $result = $this->send(['PL.date_basis' => 'issue_date']);
        $result->assertStatus(302);
        $this->assertStringEndsWith('/settings/fx', $result->response()->getHeaderLine('Location'));

        $this->assertSame('issue_date', $this->owner()->query("SELECT date_basis FROM fx_country_settings WHERE country = 'PL'")->getRow()->date_basis);
        $this->assertGreaterThanOrEqual(1, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'fx_settings'")->getRow()->c);

        $this->assertStringContainsString('Nastavenia boli uložené.', json_encode($_SESSION['_ci_flash'] ?? $_SESSION ?? [], JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testInvalidValueGives422(): void
    {
        $this->login();
        $result = $this->send(['PL.source' => 'XYZ']);
        $result->assertStatus(422);
        $html = $this->body($result);
        $this->assertStringContainsString('Vyberte platnú hodnotu.', $html);
        $this->assertMatchesRegularExpression('/<select[^>]*is-invalid[^>]*name="PL\[source\]"/', $html);
    }

    public function testStaleRevisionGives409(): void
    {
        $this->login();
        $this->send(['PL.date_basis' => 'issue_date'])->assertStatus(302);

        $result = $this->send(['PL.date_basis' => 'delivery_date']);
        $result->assertStatus(409);
        $this->assertStringContainsString('Nastavenie kurzu medzitým niekto zmenil. Načítajte stránku znova a zopakujte úpravu.', $this->body($result));
    }

    public function testHtmxSuccessUsesHxLocation(): void
    {
        $this->login();
        $result = $this->send(['CZ.source' => 'ECB'], true, true);
        $result->assertStatus(200);
        $this->assertSame('/settings/fx', $result->response()->getHeaderLine('HX-Location'));
    }

    public function testLinks(): void
    {
        $this->login();
        $this->assertStringContainsString('href="/settings/fx"', $this->body($this->get('/more')));
        $this->assertStringContainsString('href="/settings/fx"', $this->body($this->get('/settings/legal-texts')));
    }
}
