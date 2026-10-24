<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * @internal
 */
#[Group('feature')]
final class PwaManifestTest extends AuthTestCase
{
    private function assertPwaMeta(string $body): void
    {
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $body);
        $this->assertMatchesRegularExpression('#rel="apple-touch-icon" href="[^"]*icon-180\.v1\.png#', $body);
        $this->assertStringContainsString('name="apple-mobile-web-app-capable" content="yes"', $body);
        $this->assertStringContainsString('name="apple-mobile-web-app-title" content="Work Tracker"', $body);
        $this->assertStringContainsString('name="apple-mobile-web-app-status-bar-style" content="default"', $body);
        $this->assertMatchesRegularExpression('#<script src="[^"]*js/pwa\.js[^"]*" defer>#', $body);
        $this->assertMatchesRegularExpression('/<div[^>]*data-install-hint[^>]*\shidden/', $body);
    }

    public function testLoginPageHasPwaMeta(): void
    {
        $this->assertPwaMeta((string) $this->get('/login')->response()->getBody());
    }

    public function testAppPageHasPwaMeta(): void
    {
        $this->loginAs($this->createUser());
        $result = $this->get('/');
        $result->assertStatus(200);
        $this->assertPwaMeta((string) $result->response()->getBody());
    }

    public function testManifestIsValid(): void
    {
        $m = json_decode((string) file_get_contents(FCPATH . 'manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Work Tracker', $m['name']);
        $this->assertSame('Work Tracker', $m['short_name']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('/', $m['start_url']);
        $this->assertSame('/', $m['scope']);
        $this->assertSame('#0d6efd', $m['theme_color']);
        $this->assertSame('#f5f6f8', $m['background_color']);

        $sizes    = array_column($m['icons'], 'sizes');
        $purposes = array_column($m['icons'], 'purpose');
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertContains('maskable', $purposes);
    }

    public function testIconsExistWithDeclaredSizes(): void
    {
        $m     = json_decode((string) file_get_contents(FCPATH . 'manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
        $icons = array_column($m['icons'], 'sizes', 'src');
        $icons['/assets/icons/icon-180.v1.png'] = '180x180';

        foreach ($icons as $src => $sizes) {
            $this->assertFileExists(FCPATH . ltrim($src, '/'));
            $info = getimagesize(FCPATH . ltrim($src, '/'));
            $this->assertNotFalse($info);
            $this->assertSame($sizes, $info[0] . 'x' . $info[1], $src);
        }
    }
}
