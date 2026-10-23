<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('feature')]
final class LayoutTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTPS', 'on');
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
        $this->assertSame(['204', '[23]..', '422', '403', '[45]..', '...'], $codes);

        $by = array_column($cfg['responseHandling'], null, 'code');
        $this->assertTrue($by['422']['swap']);
        $this->assertFalse($by['422']['error']);
        $this->assertTrue($by['403']['swap']);
        $this->assertTrue($by['403']['error']);
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
}
