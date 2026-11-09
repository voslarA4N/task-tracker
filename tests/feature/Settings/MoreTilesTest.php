<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Stranka Viac ako mriezka dlazdic s ikonami.
 *
 * @internal
 */
#[Group('feature')]
final class MoreTilesTest extends AuthTestCase
{
    private const HREFS = [
        '/reports/month', '/reports/accountant', '/settings/entities', '/settings/clients',
        '/settings/legal-texts', '/settings/fx', '/settings/documents', '/settings/limits',
        '/settings/audit', '/settings/users', '/settings/email-templates', '/settings/security',
    ];

    private function fetch(string $path): TestResponse
    {
        $this->withSession();

        return $this->get($path);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    public function testTilesRenderWithDistinctIcons(): void
    {
        $this->loginAs($this->createUser());
        $result = $this->fetch('/more');
        $result->assertStatus(200);
        $body = $this->body($result);

        $count = preg_match_all('#<a class="more-tile" href="([^"]+)">(.*?)</a>#s', $body, $m);
        $this->assertSame(12, $count);
        $this->assertEqualsCanonicalizing(self::HREFS, $m[1]);

        $icons = [];
        foreach ($m[2] as $inner) {
            $this->assertSame(1, preg_match('#<svg [^>]*aria-hidden="true"[^>]*focusable="false"[^>]*>(.*?)</svg>#s', $inner, $s));
            $icons[] = $s[1];
        }
        $this->assertCount(12, array_unique($icons), 'Ikony musia byt navzajom odlisne.');
    }

    public function testLogoutFormAfterGridWithCsrf(): void
    {
        $this->loginAs($this->createUser());
        $body = $this->body($this->fetch('/more'));

        $lastTile = strrpos($body, 'class="more-tile"');
        $form     = strpos($body, 'action="/logout"');
        $this->assertNotFalse($lastTile);
        $this->assertNotFalse($form);
        $this->assertGreaterThan(strpos($body, '</a>', $lastTile), $form);

        $this->assertSame(1, preg_match('#<form method="post" action="/logout"[^>]*>(.*?)</form>#s', $body, $f));
        $this->assertStringContainsString('name="' . config('Security')->tokenName . '"', $f[1]);
        $this->assertStringContainsString('type="submit"', $f[1]);
        $this->assertStringNotContainsString('more-tile"', $f[0]);
    }

    public function testNoInlineStyleOrHandlers(): void
    {
        $this->loginAs($this->createUser());
        $body = $this->body($this->fetch('/more'));

        $this->assertStringNotContainsString(' style=', $body);
        $this->assertStringNotContainsString('onclick', $body);
    }
}
