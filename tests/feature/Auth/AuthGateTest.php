<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Filters\AuthGate;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Tests\Support\AuthTestCase;

/**
 * @internal
 */
#[Group('feature')]
final class AuthGateTest extends AuthTestCase
{
    public function testAllowlistIsFixed(): void
    {
        $this->assertSame(['login', 'logout', 'offline', 'sw.js', 'manifest.webmanifest'], (new ReflectionClass(AuthGate::class))->getConstant('ALLOW_EXACT'));
        $this->assertSame(['2fa', 'password/', 'assets/'], (new ReflectionClass(AuthGate::class))->getConstant('ALLOW_PREFIX'));
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        foreach (['/', '/demo'] as $path) {
            $result = $this->get($path);
            $result->assertStatus(302);
            $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'), $path);
        }
    }

    public function testAnonymousHtmxGets401WithHxRedirect(): void
    {
        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/demo');
        $result->assertStatus(401);
        $this->assertSame('/login', $result->response()->getHeaderLine('HX-Redirect'));
        $this->assertStringNotContainsString('<html', (string) $result->response()->getBody());
    }

    public function testPendingIsSentToTwoFactor(): void
    {
        $this->startPending($this->createUser());

        $result = $this->get('/');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/2fa', $result->response()->getHeaderLine('Location'));

        $htmx = $this->withHeaders(['HX-Request' => 'true'])->get('/');
        $htmx->assertStatus(401);
        $this->assertSame('/2fa', $htmx->response()->getHeaderLine('HX-Redirect'));
        $this->assertSame('', (string) $htmx->response()->getBody());
    }

    public function testAdminSeesHome(): void
    {
        $this->loginAs($this->createUser());

        $result = $this->get('/');
        $result->assertStatus(200);
        $this->assertStringContainsString('id="app-alert"', (string) $result->response()->getBody());
    }

    public function testAccountantGets403(): void
    {
        $this->loginAs($this->createUser('acc@example.test', 'accountant'));

        $this->get('/')->assertStatus(403);
    }

    public function testEveryRegisteredRouteIsDeniedToAnonymous(): void
    {
        $routes = service('routes');
        $routes->loadRoutes();
        $checked = 0;

        foreach (['get', 'post', 'put', 'patch', 'delete'] as $verb) {
            foreach (array_keys($routes->getRoutes(strtoupper($verb))) as $route) {
                $path = '/' . trim((string) preg_replace(['/\(:num\)/', '/\(:[^)]*\)/'], ['1', 'x'], $route), '/');

                if ($this->isAllowlisted($path)) {
                    continue;
                }

                $result = $this->anon($verb, $path, $verb === 'get' ? [] : ['_t' => '1']);
                $this->assertSame(302, $result->response()->getStatusCode(), strtoupper($verb) . ' ' . $path);
                $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'), strtoupper($verb) . ' ' . $path);
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(4, $checked);
    }

    private function isAllowlisted(string $path): bool
    {
        $p = trim($path, '/');

        if (in_array($p, AuthGate::ALLOW_EXACT, true)) {
            return true;
        }

        foreach (AuthGate::ALLOW_PREFIX as $prefix) {
            if (str_ends_with($prefix, '/') ? str_starts_with($p, $prefix) : ($p === $prefix || str_starts_with($p, $prefix . '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $params
     */
    private function anon(string $method, string $path, array $params): TestResponse
    {
        if ($method !== 'get') {
            $this->withHeaders(['X-CSRF-TOKEN' => csrf_hash()]);
        }

        return $this->call($method, $path, $params);
    }
}
