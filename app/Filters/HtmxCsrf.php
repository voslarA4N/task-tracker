<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;

/**
 * CSRF overenie bez presmerovania: pri zlyhani vrati 403.
 * Pre htmx fragment do #app-alert, inak celu stranku.
 */
final class HtmxCsrf implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        try {
            service('security')->verify($request);
        } catch (SecurityException) {
            $response = service('response')->setStatusCode(403);

            if ($request->getHeaderLine('HX-Request') === 'true') {
                return $response
                    ->setHeader('HX-Retarget', '#app-alert')
                    ->setHeader('HX-Reswap', 'innerHTML')
                    ->setBody(view('fragments/alert', ['message' => lang('App.csrfFailed')]));
            }

            return $response->setBody(view('errors/csrf_403', ['title' => lang('App.title')]));
        }

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }
}
