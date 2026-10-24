<?php

declare(strict_types=1);

namespace App\Filters;

use App\Authentication\Actions\TotpAction;
use App\Libraries\Auth\AuditLog;
use App\Libraries\Auth\AuthEvent;
use App\Libraries\Auth\TrustedDeviceService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Prihlasenie z platnej cookie dovernoho zariadenia, ak relacia nema pouzivatela (D-15, D-16).
 * Shield remember-me ostava vypnuty (Pitfall 1).
 */
final class DeviceLogin implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $authenticator = auth('session')->getAuthenticator();

        if (! $authenticator instanceof Session || $authenticator->loggedIn() || $authenticator->isPending()) {
            return null;
        }

        $cookieName = TrustedDeviceService::COOKIE_PREFIX . TrustedDeviceService::COOKIE_NAME;
        $token      = $request->getCookie($cookieName);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $svc      = new TrustedDeviceService();
        $now      = service('clock')->now();
        $response = service('response');
        $device   = $svc->findValid($token, $now);
        $user     = $device === null ? null : model(UserModel::class)->findById($device['user_id']);

        if ($device === null || $user === null) {
            $response->setCookie($svc->expiredCookie());

            return null;
        }

        // Zostatok z neukonceneho prihlasenia nesmie vyvolat druhy faktor (Pitfall 2).
        model(UserIdentityModel::class)->deleteIdentitiesByType($user, TotpAction::TYPE);

        $authenticator->login($user);

        session()->set([
            'device_id'     => $device['id'],
            'auth_epoch'    => $svc->currentEpoch((int) $user->id),
            'last_activity' => $now->getTimestamp(),
        ]);

        $new = $svc->rotate($device['id'], $token, $now);

        if ($new !== '') {
            $response->setCookie($svc->cookie($new, $now->setTimestamp($device['expires_ts'])));
        }

        $svc->touch($device['id'], $now);
        (new AuditLog())->record(AuthEvent::DeviceLogin, true, (int) $user->id);

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        // redirect() vytvara novu odpoved: cookies nastavene v before filtri (rotacia, zmazanie) by sa stratili.
        $shared = service('response');

        if ($shared === $response) {
            return null;
        }

        foreach ($shared->getCookieStore() as $cookie) {
            if (! $response->hasCookie($cookie->getName(), null, $cookie->getPrefix())) {
                $response->setCookie($cookie);
            }
        }

        return null;
    }
}
