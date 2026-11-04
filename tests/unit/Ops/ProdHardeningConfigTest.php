<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * D-13: poistka proti regresii konfiguracie vykonu a hlaviciek (T-12-20).
 *
 * @internal
 */
#[Group('unit')]
final class ProdHardeningConfigTest extends CIUnitTestCase
{
    private function file(string $rel): string
    {
        $c = file_get_contents(ROOTPATH . $rel);
        $this->assertIsString($c, $rel . ' sa nedá prečítať');

        return $c;
    }

    public function testNginxServesHttp2GzipAndImmutableCache(): void
    {
        $c = $this->file('docker/nginx/templates/default.conf.template');

        $this->assertStringContainsString('http2 on;', $c);
        $this->assertStringContainsString('gzip on;', $c);
        $this->assertMatchesRegularExpression('/gzip_types[^;]*application\/javascript/', $c);
        $this->assertStringContainsString('max-age=31536000, immutable', $c);
        $this->assertStringContainsString('include /etc/nginx/conf.d/snippets/security-headers.conf;', $c);
    }

    public function testOpcacheProdSettings(): void
    {
        $c = $this->file('docker/php/conf.d/opcache-prod.ini');

        $this->assertStringContainsString('validate_timestamps=0', $c);
        $this->assertStringContainsString('jit=off', $c);
        $this->assertStringContainsString('opcache.preload=', $c);
    }

    public function testDockerfileUsesClassmapAuthoritative(): void
    {
        $this->assertStringContainsString('--classmap-authoritative', $this->file('Dockerfile'));
    }

    public function testDatabaseSharedBuffersStaysSmall(): void
    {
        $c = $this->file('compose.server.yaml');

        $this->assertSame(1, preg_match_all('/shared_buffers=(\d+)MB/', $c, $m), 'shared_buffers v compose.server.yaml');
        $this->assertLessThanOrEqual(64, (int) $m[1][0]);
    }

    public function testCspHasNoUnsafeDirectives(): void
    {
        $c = $this->file('docker/nginx/templates/snippets/security-headers.conf.template');

        $this->assertStringContainsString('Content-Security-Policy', $c);
        $this->assertStringNotContainsString('unsafe-', $c);
    }
}
