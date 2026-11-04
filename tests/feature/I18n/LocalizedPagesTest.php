<?php

declare(strict_types=1);

namespace Tests\Feature\I18n;

use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Klucove obrazovky v PL a CS bez surovych klucov a anglickeho fallbacku (UI-02, UI-SPEC D).
 *
 * @internal
 */
#[Group('feature')]
final class LocalizedPagesTest extends AuthTestCase
{
    private const PAGES = ['/', '/tasks', '/invoices/new', '/invoices', '/reports/month', '/settings/limits', '/settings/security', '/more'];

    private const EN_PROBES = ['App.navOverview', 'App.navTasks', 'App.navInvoices', 'App.navMore', 'Account.securityTitle'];

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localePages(): iterable
    {
        foreach (['pl', 'cs'] as $locale) {
            foreach (self::PAGES as $url) {
                yield $locale . ' ' . $url => [$locale, $url];
            }
        }
    }

    #[DataProvider('localePages')]
    public function testPageIsFullyTranslated(string $locale, string $url): void
    {
        $admin = $this->createUser('owner@example.test', 'admin');
        $this->enrollTotp($admin);
        $this->owner()->query('UPDATE user_security SET locale = ? WHERE user_id = ?', [$locale, $admin->id]);
        $this->loginAs($admin);

        $result = $this->get($url);
        $result->assertStatus(200);
        $this->assertTranslated((string) $result->response()->getBody(), $locale);
    }

    #[DataProvider('anonymousLocales')]
    public function testLoginIsTranslatedFromAcceptLanguage(string $locale): void
    {
        $result = $this->withHeaders(['Accept-Language' => $locale])->get('/login');
        $result->assertStatus(200);
        $this->assertTranslated((string) $result->response()->getBody(), $locale);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function anonymousLocales(): array
    {
        return ['pl' => ['pl'], 'cs' => ['cs']];
    }

    private function assertTranslated(string $html, string $locale): void
    {
        $this->assertStringContainsString('<html lang="' . $locale . '"', $html);
        $this->assertDoesNotMatchRegularExpression('/<button\b[^>]*\btext-nowrap\b/', $html);

        $text = preg_replace(['#<(script|style)\b.*?</\1>#si', '#<[^>]+>#', '#[\w.+-]+@[\w.-]+#'], ' ', $html) ?? '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        $this->assertSame(0, preg_match('/\b[A-Z][a-zA-Z]+\.[a-z][A-Za-z0-9_]+\b/', $text, $raw), 'Surovy kluc: ' . ($raw[0] ?? ''));

        foreach (self::EN_PROBES as $key) {
            $en    = lang($key, [], 'en');
            $local = lang($key, [], $locale);
            $this->assertNotSame($key, $en, "Probe {$key} neexistuje");

            if ($en !== $local) {
                $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}])' . preg_quote($en, '/') . '(?![\p{L}])/u', $text, "Anglicky retazec '{$en}' v {$locale}");
            }
        }
    }
}
