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
    <title><?= esc(($title ?? '') . ' · ' . lang('Login.appName')) ?></title>
    <link rel="stylesheet" href="<?= esc(asset_url('vendor/bootstrap-5.3.8.min.css'), 'attr') ?>">
    <link rel="stylesheet" href="<?= esc(asset_url('css/app.css'), 'attr') ?>">
    <script src="<?= esc(asset_url('vendor/htmx-2.0.11.min.js'), 'attr') ?>" defer></script>
    <script src="<?= esc(asset_url('vendor/bootstrap.bundle-5.3.8.min.js'), 'attr') ?>" defer></script>
    <script src="<?= esc(asset_url('js/app.js'), 'attr') ?>" defer></script>
    <script src="<?= esc(asset_url('vendor/alpine-csp-3.17.5.min.js'), 'attr') ?>" defer></script>
</head>
<body hx-headers="<?= esc(json_encode([csrf_header() => csrf_hash()], JSON_THROW_ON_ERROR), 'attr') ?>"
      data-msg-network="<?= esc(lang('App.errorNetwork'), 'attr') ?>"
      data-msg-server="<?= esc(lang('App.errorServer'), 'attr') ?>"
      x-data="appShell">
    <main class="auth-wrap">
        <h1 class="auth-logo h5"><?= esc(lang('Login.appName')) ?></h1>
        <div id="app-alert" role="alert" aria-live="polite" hidden></div>
        <?= $this->renderSection('content') ?>
    </main>
</body>
</html>
