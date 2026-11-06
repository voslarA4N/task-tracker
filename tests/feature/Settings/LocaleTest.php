<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Vyber jazyka rozhrania na /settings/security (UI-02, D-01).
 *
 * @internal
 */
#[Group('feature')]
final class LocaleTest extends AuthTestCase
{
    private function fetch(string $path): TestResponse
    {
        $this->withSession();

        return $this->get($path);
    }

    /**
     * @param array<string, string> $data
     */
    private function postLocale(array $data, bool $csrf = true): TestResponse
    {
        $this->withSession();

        return $this->call('post', '/settings/security/locale', $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function storedLocale(User $user): string
    {
        return (string) $this->owner()->query('SELECT locale FROM user_security WHERE user_id = ?', [$user->id])->getRow()->locale;
    }

    private function localeEvents(): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM auth_events WHERE event = 'locale_changed'")->getRow()->c;
    }

    public function testCardIsFirstWithFourRadiosAndCheckedCurrent(): void
    {
        $this->loginAs($this->createUser());

        $html = $this->body($this->fetch('/settings/security'));

        $this->assertStringContainsString('id="sec-language"', $html);
        $this->assertStringContainsString('action="/settings/security/locale"', $html);
        $this->assertSame(4, preg_match_all('#<input[^>]+name="locale"[^>]*>#', $html, $radios));
        $values = [];

        foreach ($radios[0] as $tag) {
            preg_match('#value="([^"]+)"#', $tag, $v);
            $values[] = $v[1];
        }
        $this->assertSame(['sk', 'pl', 'cs', 'en'], $values);

        foreach (['Slovenčina', 'Polski', 'Čeština', 'English'] as $name) {
            $this->assertStringContainsString($name, $html);
        }
        $this->assertMatchesRegularExpression('#<input[^>]+value="sk"[^>]+checked|<input[^>]+checked[^>]+value="sk"#', $html);
        $this->assertLessThan(strpos($html, 'id="sec-password"'), strpos($html, 'id="sec-language"'));
    }

    public function testPostPolishStoresAuditsAndFlashesInNewLanguage(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $result = $this->postLocale(['locale' => 'pl']);

        $result->assertStatus(302);
        $this->assertStringEndsWith('/settings/security', $result->response()->getHeaderLine('Location'));
        $this->assertSame('pl', $this->storedLocale($user));
        $this->assertSame(1, $this->localeEvents());
        $this->assertSame('true', $this->owner()->query("SELECT success::text AS s FROM auth_events WHERE event = 'locale_changed'")->getRow()->s);
        $notice = session()->getFlashdata('app_notice');
        $this->assertIsArray($notice);
        $this->assertStringContainsString('Język interfejsu został zmieniony.', (string) $notice['message']);
    }

    public function testPageIsTranslatedAfterSwitchToPolish(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->postLocale(['locale' => 'pl']);

        $html = $this->body($this->fetch('/settings/security'));

        $this->assertStringContainsString('<html lang="pl"', $html);
        $this->assertStringContainsString('Język interfejsu', $html);
        $this->assertStringContainsString('Przegląd', $html);
    }

    public function testPostCzechFlash(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->postLocale(['locale' => 'cs'])->assertStatus(302);

        $this->assertSame('cs', $this->storedLocale($user));
        $notice = session()->getFlashdata('app_notice');
        $this->assertIsArray($notice);
        $this->assertStringContainsString('Jazyk rozhraní byl změněn.', (string) $notice['message']);
    }

    #[DataProvider('provideInvalidLocaleIsRejectedWithoutWrite')]
    public function testInvalidLocaleIsRejectedWithoutWrite(string $value): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $result = $this->postLocale(['locale' => $value]);

        $result->assertStatus(422);
        $this->assertStringContainsString('Zvolený jazyk nie je podporovaný.', $this->body($result));
        $this->assertSame('sk', $this->storedLocale($user));
        $this->assertSame(0, $this->localeEvents());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidLocaleIsRejectedWithoutWrite(): iterable
    {
        return ['de' => ['de'], 'cz' => ['cz'], 'empty' => [''], 'upper' => ['SK']];
    }

    public function testSameLocaleDoesNotAudit(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->postLocale(['locale' => 'sk'])->assertStatus(302);

        $this->assertNotNull(session()->getFlashdata('app_notice'));
        $this->assertSame(0, $this->localeEvents());
    }

    public function testMissingCsrfIsForbidden(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->postLocale(['locale' => 'pl'], false)->assertStatus(403);
        $this->assertSame('sk', $this->storedLocale($user));
    }

    public function testAnonymousPostRedirectsToLogin(): void
    {
        $result = $this->call('post', '/settings/security/locale', ['locale' => 'pl', csrf_token() => csrf_hash()]);

        $result->assertStatus(302);
        $this->assertStringContainsString('/login', $result->response()->getHeaderLine('Location'));
    }

    public function testAcceptLanguageDecidesBeforeLogin(): void
    {
        $html = $this->body($this->withHeaders(['Accept-Language' => 'cs-CZ,cs;q=0.9'])->get('/login'));
        $this->assertStringContainsString('<html lang="cs"', $html);

        $html = $this->body($this->withHeaders(['Accept-Language' => 'de'])->get('/login'));
        $this->assertStringContainsString('<html lang="sk"', $html);
    }
}
