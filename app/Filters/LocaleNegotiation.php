<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use Config\Services;

/**
 * Pred prihlasenim jazyk vyberie CI4 z Accept-Language (App::$negotiateLocale);
 * pre prihlaseneho (aj pending) pouzivatela plati user_security.locale (D-23).
 */
final class LocaleNegotiation implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        // V prevadzke to uz urobil konstruktor requestu; tu sa vyberie z aktualnych hlaviciek
        // (nezdielany negotiator, v testoch sa hlavicky nastavuju az po vytvoreni requestu).
        $supported = config('App')->supportedLocales;
        $this->apply($request, Services::negotiator($request, false)->language($supported));

        $auth          = auth('session');
        $authenticator = $auth->getAuthenticator();
        $pending       = $authenticator instanceof Session && $authenticator->isPending();

        if (! $auth->loggedIn() && ! $pending) {
            return null;
        }

        // Shield Auth::user() vracia pre pending relaciu null, pouzivatel je iba v getPendingUser() (WR-05).
        $user = $auth->loggedIn()
            ? $auth->user()
            : ($authenticator instanceof Session ? $authenticator->getPendingUser() : null);

        if ($user === null) {
            return null;
        }

        $row = db_connect()->table('user_security')->select('locale')->where('user_id', $user->id)->get()->getRowArray();

        if (is_array($row) && is_string($row['locale'] ?? null) && in_array($row['locale'], config('App')->supportedLocales, true)) {
            $this->apply($request, $row['locale']);
        }

        return null;
    }

    /**
     * IncomingRequest::setLocale() nemeni jazyk sluzby `language`, ktoru pouzivaju lang() vo view.
     */
    private function apply(IncomingRequest $request, string $locale): void
    {
        $request->setLocale($locale);
        service('language')->setLocale($request->getLocale());
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }
}
