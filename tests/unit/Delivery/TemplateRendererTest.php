<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Libraries\Delivery\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TemplateRendererTest extends TestCase
{
    public function testRendersWhitelistedAndKeepsUnknown(): void
    {
        $r = (new TemplateRenderer())->render('Faktúra {{number}}', "Suma {{total}}\n\n{{unknown}}", ['number' => '202610001', 'total' => '1 080,00 €']);

        $this->assertSame('Faktúra 202610001', $r['subject']);
        $this->assertSame("Suma 1 080,00 €\n\n{{unknown}}", $r['text']);
    }

    public function testValuesAreNotExpandedTwice(): void
    {
        $r = (new TemplateRenderer())->render('{{client_name}}', '{{client_name}} {{total}}', ['client_name' => 'X {{total}}', 'total' => '5']);

        $this->assertSame('X {{total}}', $r['subject']);
        $this->assertSame('X {{total}} 5', $r['text']);
    }

    public function testSubjectHasNoLineBreaks(): void
    {
        $r = (new TemplateRenderer())->render('Hi {{client_name}}', 'b', ['client_name' => "A\r\nBcc: evil@x.test"]);

        $this->assertStringNotContainsString("\r", $r['subject']);
        $this->assertStringNotContainsString("\n", $r['subject']);
    }

    public function testHtmlEscapesAndBuildsParagraphs(): void
    {
        $html = TemplateRenderer::html("Dobrý deň,\n\nriadok <b>1</b>\nriadok 2");

        $this->assertStringContainsString('<p>Dobrý deň,</p>', $html);
        $this->assertStringContainsString('&lt;b&gt;1&lt;/b&gt;', $html);
        $this->assertStringContainsString('<br', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testUnknownPlaceholders(): void
    {
        $this->assertSame(['foo'], TemplateRenderer::unknownPlaceholders('{{number}} {{foo}} {{ total }}'));
    }
}
