<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

final class Pwa extends BaseController
{
    public function serviceWorker(): ResponseInterface
    {
        helper('asset');

        $precache = [
            '/offline',
            asset_url('vendor/bootstrap-5.3.8.min.css'),
            asset_url('css/app.css'),
            asset_url('vendor/htmx-2.0.11.min.js'),
            asset_url('vendor/bootstrap.bundle-5.3.8.min.js'),
            asset_url('js/app.js'),
            asset_url('js/pwa.js'),
            asset_url('vendor/alpine-csp-3.17.5.min.js'),
            asset_url('icons/icon.svg'),
            '/assets/icons/icon-192.v1.png',
            '/assets/icons/icon-512.v1.png',
        ];

        $json    = json_encode($precache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $version = substr(sha1($json), 0, 12);
        $source  = (string) file_get_contents(APPPATH . 'Views/pwa/service_worker.js');
        $source  = str_replace(['__PRECACHE__', '__SW_VERSION__'], [$json, $version], $source);

        // Response nastavuje no-store v konstruktore; SW ma mat presne no-cache.
        return $this->response
            ->removeHeader('Cache-Control')
            ->setHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->setHeader('Cache-Control', 'no-cache')
            ->setHeader('Service-Worker-Allowed', '/')
            ->setBody($source);
    }

    public function offline(): string
    {
        return view('pwa/offline');
    }
}
