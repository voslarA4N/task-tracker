<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Exceptions\PageNotFoundException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * @internal
 */
#[Group('feature')]
final class LayoutTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs($this->createUser());
    }

    private function homeHtml(): string
    {
        $result = $this->get('/');
        $result->assertStatus(200);

        return (string) $result->getBody();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    public function testHeaderHasSingleLogoutForm(): void
    {
        $xp    = $this->xpath($this->homeHtml());
        $forms = $xp->query('//form[@action="/logout"]');
        $this->assertSame(1, $forms->length);
        $form = $forms->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame(1, $xp->query('ancestor::header[contains(@class,"app-header")]', $form)->length);
        $this->assertSame(0, $xp->query('ancestor::nav', $form)->length);
        $this->assertSame('post', strtolower($form->getAttribute('method')));
        $this->assertSame(1, $xp->query('.//input[@name="' . config('Security')->tokenName . '"]', $form)->length);

        $buttons = $xp->query('.//button[@type="submit"][contains(@class,"app-logout")]', $form);
        $this->assertSame(1, $buttons->length);
        $btn = $buttons->item(0);
        $this->assertInstanceOf(DOMElement::class, $btn);
        $this->assertStringNotContainsString('btn-danger', $btn->getAttribute('class'));
        $this->assertStringNotContainsString('text-danger', $btn->getAttribute('class'));

        $svgs = $xp->query('./svg', $btn);
        $this->assertSame(1, $svgs->length);
        $svg = $svgs->item(0);
        $this->assertInstanceOf(DOMElement::class, $svg);
        $this->assertSame('true', $svg->getAttribute('aria-hidden'));
        $this->assertSame('false', $svg->getAttribute('focusable'));
        $this->assertSame('20', $svg->getAttribute('width'));
        $this->assertSame(1, $xp->query('.//path[@d="M16 17l5-5-5-5"]', $svg)->length);
        $this->assertSame(lang('Login.logout'), trim($btn->textContent));
    }

    public function testTabbarHasNoLogout(): void
    {
        $xp = $this->xpath($this->homeHtml());
        $this->assertSame(0, $xp->query('//nav[contains(@class,"app-tabbar")]//form')->length);
        $this->assertSame(0, $xp->query('//nav[contains(@class,"app-topnav")]//form')->length);
    }

    public function testNavLinksHaveIcons(): void
    {
        $xp     = $this->xpath($this->homeHtml());
        $hrefs  = ['/', '/tasks', '/invoices', '/more'];
        $labels = [lang('App.navOverview'), lang('App.navTasks'), lang('App.navInvoices'), lang('App.navMore')];

        foreach (['app-topnav', 'app-tabbar'] as $cls) {
            $links = $xp->query('//nav[contains(@class,"' . $cls . '")]/a');
            $this->assertSame(4, $links->length, $cls);
            $bodies = [];

            foreach ($links as $i => $a) {
                $this->assertInstanceOf(DOMElement::class, $a);
                $this->assertSame($hrefs[$i], $a->getAttribute('href'));
                $svgs = $xp->query('./svg', $a);
                $this->assertSame(1, $svgs->length, $cls . ' ' . $hrefs[$i]);
                $svg = $svgs->item(0);
                $this->assertInstanceOf(DOMElement::class, $svg);
                $this->assertSame('nav-icon', $svg->getAttribute('class'));
                $this->assertSame('true', $svg->getAttribute('aria-hidden'));
                $this->assertSame('false', $svg->getAttribute('focusable'));
                $this->assertSame($labels[$i], trim($a->textContent));
                $bodies[] = $svg->ownerDocument->saveHTML($svg);
            }
            $this->assertCount(4, array_unique($bodies));
        }
    }

    #[DataProvider('provideNavAriaCurrentOnlyOnActive')]
    public function testNavAriaCurrentOnlyOnActive(string $url, string $href): void
    {
        $result = $this->get($url);
        $result->assertStatus(200);
        $xp = $this->xpath((string) $result->getBody());

        foreach (['app-topnav', 'app-tabbar'] as $cls) {
            $cur = $xp->query('//nav[contains(@class,"' . $cls . '")]/a[@aria-current="page"]');
            $this->assertSame(1, $cur->length, $cls);
            $a = $cur->item(0);
            $this->assertInstanceOf(DOMElement::class, $a);
            $this->assertSame($href, $a->getAttribute('href'));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNavAriaCurrentOnlyOnActive(): iterable
    {
        yield 'home' => ['/', '/'];

        yield 'tasks' => ['/tasks', '/tasks'];

        yield 'invoices' => ['/invoices', '/invoices'];

        yield 'more' => ['/more', '/more'];
    }

    public function testHomeRendersLayoutAssets(): void
    {
        $html = $this->homeHtml();

        $this->assertStringContainsString('<html lang="sk"', $html);
        $this->assertStringContainsString('viewport-fit=cover', $html);
        $this->assertStringContainsString('/assets/vendor/bootstrap-5.3.8.min.css', $html);
        $this->assertStringContainsString('/assets/vendor/htmx-2.0.11.min.js', $html);
        $this->assertStringContainsString('/assets/vendor/alpine-csp-3.17.5.min.js', $html);
        $this->assertStringContainsString('/assets/js/app.js?v=', $html);
        $this->assertLessThan(strpos($html, 'alpine-csp-3.17.5'), strpos($html, '/assets/js/app.js'));
        $this->assertStringContainsString('id="app-alert"', $html);
        $this->assertStringContainsString('class="app-tabbar', $html);
        $this->assertStringNotContainsString('Welcome to CodeIgniter', $html);
    }

    public function testHtmxConfigMeta(): void
    {
        $xp   = $this->xpath($this->homeHtml());
        $node = $xp->query('//meta[@name="htmx-config"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        $cfg = json_decode($node->getAttribute('content'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($cfg['allowEval']);
        $this->assertFalse($cfg['allowScriptTags']);
        $this->assertFalse($cfg['includeIndicatorStyles']);
        $this->assertSame(0, $cfg['historyCacheSize']);

        $codes = array_column($cfg['responseHandling'], 'code');
        $this->assertSame(['204', '[23]..', '422', '403', '409', '502', '[45]..', '...'], $codes);

        $by = array_column($cfg['responseHandling'], null, 'code');
        $this->assertTrue($by['422']['swap']);
        $this->assertFalse($by['422']['error']);
        $this->assertTrue($by['403']['swap']);
        $this->assertTrue($by['403']['error']);
        $this->assertTrue($by['409']['swap']);
    }

    public function testBodyHasCsrfHxHeaders(): void
    {
        $xp   = $this->xpath($this->homeHtml());
        $body = $xp->query('//body')->item(0);
        $this->assertInstanceOf(DOMElement::class, $body);

        $headers = json_decode($body->getAttribute('hx-headers'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey(csrf_header(), $headers);
        $this->assertNotSame('', $headers[csrf_header()]);
    }

    public function testNotFoundHasNoInlineCode(): void
    {
        // V testovacom prostredi FeatureTestTrait prepusta PageNotFoundException; 404 stranku renderuje handler vyhnimok.
        try {
            $this->get('/neexistuje-xyz');
            $this->fail('Ocakavana PageNotFoundException (404)');
        } catch (PageNotFoundException $e) {
            $this->assertSame(404, $e->getCode());
        }

        $html = view('errors/html/error_404', ['message' => 'x', 'code' => 404]);

        $this->assertStringNotContainsStringIgnoringCase('<style', $html);
        $this->assertSame(0, preg_match('/<script\b(?![^>]*\bsrc=)[^>]*>/i', $html));
    }

    /**
     * WR-06: chybove stranky nacitaju app.css s verziou (inak ostane navzdy
     * v immutable cache) a lang podla poziadavky, nie natvrdo.
     */
    public function testErrorPagesUseVersionedCssAndRequestLocale(): void
    {
        service('request')->setLocale('en');

        foreach (['error_400', 'error_404', 'production'] as $page) {
            $html = view('errors/html/' . $page, ['message' => 'x', 'code' => 500]);

            $this->assertStringContainsString('<html lang="en">', $html, $page);
            $this->assertMatchesRegularExpression('#href="/assets/css/app\.css\?v=\d+"#', html_entity_decode($html, ENT_QUOTES | ENT_HTML5), $page);
        }
    }

    /**
     * D-12 / R9: hlavne stranky v sk, pl a cs nemaju inline style ani inline script.
     */
    #[DataProvider('provideMainPagesHaveNoInlineStyleOrScript')]
    public function testMainPagesHaveNoInlineStyleOrScript(string $locale, string $url): void
    {
        // Pouzivatel z setUp() je uz prihlaseny; zmenime mu len jazyk.
        $this->owner()->query('UPDATE user_security SET locale = ?', [$locale]);

        $result = $this->get($url);
        $result->assertStatus(200);
        $html = (string) $result->response()->getBody();

        $this->assertStringContainsString('<html lang="' . $locale . '"', $html);
        $this->assertSame(0, preg_match('/\sstyle\s*=/i', $html), "inline style na {$url} ({$locale})");
        $this->assertSame(0, preg_match('/<script\b(?![^>]*\bsrc=)[^>]*>/i', $html), "inline script na {$url} ({$locale})");
        $this->assertMatchesRegularExpression('#/assets/css/app\.css\?v=\d+#', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMainPagesHaveNoInlineStyleOrScript(): iterable
    {
        $urls = ['/', '/tasks', '/invoices', '/invoices/new', '/reports/month', '/reports/accountant', '/settings/security', '/more'];

        foreach (['sk', 'pl', 'cs'] as $locale) {
            foreach ($urls as $url) {
                yield $locale . ' ' . $url => [$locale, $url];
            }
        }
    }
}
