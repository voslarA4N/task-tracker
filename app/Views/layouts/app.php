<?php

declare(strict_types=1);

helper(['asset', 'security']);

$htmxConfig = [
    'allowEval'              => false,
    'allowScriptTags'        => false,
    'includeIndicatorStyles' => false,
    'historyCacheSize'       => 0,
    'responseHandling'       => [
        ['code' => '204', 'swap' => false],
        ['code' => '[23]..', 'swap' => true],
        ['code' => '422', 'swap' => true, 'error' => false],
        ['code' => '403', 'swap' => true, 'error' => true],
        ['code' => '[45]..', 'swap' => false, 'error' => true],
        ['code' => '...', 'swap' => false],
    ],
];
?>
<!doctype html>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0d6efd">
    <meta name="htmx-config" content="<?= esc(json_encode($htmxConfig, JSON_THROW_ON_ERROR), 'attr') ?>">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="<?= esc(asset_url('icons/icon-180.v1.png'), 'attr') ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= esc(lang('Pwa.appName'), 'attr') ?>">
    <title><?= esc($title ?? lang('App.title')) ?></title>
    <link rel="stylesheet" href="<?= esc(asset_url('vendor/bootstrap-5.3.8.min.css'), 'attr') ?>">
    <link rel="stylesheet" href="<?= esc(asset_url('css/app.css'), 'attr') ?>">
    <script src="<?= esc(asset_url('vendor/htmx-2.0.11.min.js'), 'attr') ?>" defer></script>
    <script src="<?= esc(asset_url('vendor/bootstrap.bundle-5.3.8.min.js'), 'attr') ?>" defer></script>
    <script src="<?= esc(asset_url('js/app.js'), 'attr') ?>" defer></script>
    <script src="<?= esc(asset_url('vendor/alpine-csp-3.17.5.min.js'), 'attr') ?>" defer></script>
    <script src="<?= esc(asset_url('js/pwa.js'), 'attr') ?>" defer></script>
</head>
<body hx-headers="<?= esc(json_encode([csrf_header() => csrf_hash()], JSON_THROW_ON_ERROR), 'attr') ?>"
      data-msg-network="<?= esc(lang('App.errorNetwork'), 'attr') ?>"
      data-msg-server="<?= esc(lang('App.errorServer'), 'attr') ?>"
      x-data="appShell">
    <header class="app-header">
        <div>
            <h1 class="h5 mb-0"><?= esc($title ?? lang('App.title')) ?></h1>
            <div class="app-sub"><?= $this->renderSection('subtitle') ?></div>
        </div>
        <nav class="app-topnav d-none d-lg-flex" aria-label="<?= esc(lang('App.navLabel'), 'attr') ?>">
            <?= $this->include('layouts/_nav_items') ?>
        </nav>
    </header>

    <main id="content" class="app-main container-fluid">
        <?= $this->include('layouts/_install_hint') ?>
        <?php $notice = session()->getFlashdata('app_notice'); ?>
        <?php if (is_array($notice) && isset($notice['message'])) : ?>
            <div class="alert alert-<?= ($notice['type'] ?? '') === 'warning' ? 'warning' : 'info' ?>" role="status"><?= esc((string) $notice['message']) ?></div>
        <?php endif ?>
        <div id="app-alert" role="alert" aria-live="polite" hidden></div>
        <?= $this->renderSection('content') ?>
    </main>

    <nav class="app-tabbar d-lg-none" aria-label="<?= esc(lang('App.navLabel'), 'attr') ?>">
        <?= $this->include('layouts/_nav_items') ?>
    </nav>
</body>
</html>
