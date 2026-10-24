<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Globalny deny-by-default filter: bez dokonceneho prihlasenia je dostupny iba allowlist.
 * Prihlaseny pouzivatel mimo skupiny admin dostane 403.
 */
final class AuthGate implements FilterInterface
{
    /**
     * Cesty (bez uvodneho a koncoveho lomitka) s presnou zhodou.
     */
    public const ALLOW_EXACT = ['login', 'logout', 'offline', 'sw.js', 'manifest.webmanifest'];

    /**
     * Prefixy: `2fa` pokryva `2fa` aj `2fa/...`, ostatne su prosty prefix retazca.
     */
    public const ALLOW_PREFIX = ['2fa', 'password/', 'assets/'];

    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $path = trim($request->getUri()->getPath(), '/');

        if ($this->isAllowed($path)) {
            return null;
        }

        $htmx = $request->getHeaderLine('HX-Request') === 'true';
        $auth = auth('session');

        if ($auth->loggedIn()) {
            $user = $auth->user();

            if ($user !== null && $user->inGroup('admin')) {
                return null;
            }

            $response = service('response')->setStatusCode(403);

            if ($htmx) {
                return $response
                    ->setHeader('HX-Retarget', '#app-alert')
                    ->setHeader('HX-Reswap', 'innerHTML')
                    ->setBody(view('fragments/alert', ['message' => lang('App.forbidden')]));
            }

            return $response->setBody(view('errors/forbidden_403', ['title' => lang('App.title')]));
        }

        $authenticator = $auth->getAuthenticator();
        $target        = method_exists($authenticator, 'isPending') && $authenticator->isPending() ? '/2fa' : '/login';

        if ($htmx) {
            return service('response')->setStatusCode(401)->setHeader('HX-Redirect', $target)->setBody('');
        }

        return redirect()->to($target);
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }

    private function isAllowed(string $path): bool
    {
        if (in_array($path, self::ALLOW_EXACT, true)) {
            return true;
        }

        foreach (self::ALLOW_PREFIX as $prefix) {
            if (str_ends_with($prefix, '/')) {
                if (str_starts_with($path, $prefix)) {
                    return true;
                }
            } elseif ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
