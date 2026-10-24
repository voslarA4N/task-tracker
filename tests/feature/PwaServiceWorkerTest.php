<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * @internal
 */
#[Group('feature')]
final class PwaServiceWorkerTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('asset');
    }

    public function testServiceWorkerHeadersAndBody(): void
    {
        $result = $this->get('/sw.js');
        $result->assertStatus(200);
        $res = $result->response();
        $this->assertStringContainsString('application/javascript', $res->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache', $res->getHeaderLine('Cache-Control'));
        $this->assertSame('/', $res->getHeaderLine('Service-Worker-Allowed'));

        $body = (string) $res->getBody();
        $this->assertStringContainsString('/offline', $body);
        $this->assertStringContainsString(asset_url('css/app.css'), $body);
        $this->assertStringNotContainsString('__PRECACHE__', $body);
        $this->assertStringNotContainsString('__SW_VERSION__', $body);
    }

    public function testOfflineIsAnonymousAndClean(): void
    {
        $result = $this->get('/offline');
        $result->assertStatus(200);
        $res = $result->response();
        $this->assertSame([], $res->headers()['Set-Cookie'] ?? [], 'offline must not set cookies');
        $this->assertFalse($res->hasHeader('Set-Cookie'));

        $body = (string) $res->getBody();

        foreach (['csrf', 'hx-headers', 'htmx', 'alpine'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body);
        }
        $this->assertStringContainsString('data-reload', $body);
        $this->assertStringContainsString(lang('Pwa.offlineTitle'), $body);
        $this->assertStringNotContainsString('Pwa.offlineTitle', $body);
    }

    public function testOfflineForLoggedInUserHasNoUserData(): void
    {
        $user = $this->createUser('secret.person@example.test');
        $this->loginAs($user);
        $result = $this->get('/offline');
        $result->assertStatus(200);
        $this->assertStringNotContainsString('secret.person@example.test', (string) $result->response()->getBody());
    }

    public function testEveryPrecacheEntryExists(): void
    {
        $body = (string) $this->get('/sw.js')->response()->getBody();
        $this->assertSame(1, preg_match('/const PRECACHE = (.*?);/s', $body, $m));
        $list = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(11, $list);
        $this->assertCount(11, array_unique($list));

        foreach ($list as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            if (str_starts_with($path, '/assets/')) {
                $this->assertFileExists(FCPATH . ltrim($path, '/'), $url);

                continue;
            }
            $r = $this->get($path);
            $r->assertStatus(200);
        }
    }
}
