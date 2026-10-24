<?php

declare(strict_types=1);

helper('asset');
?>
<!doctype html>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0d6efd">
    <title><?= esc(lang('Pwa.offlineTitle') . ' · ' . lang('Pwa.appName')) ?></title>
    <link rel="stylesheet" href="<?= esc(asset_url('vendor/bootstrap-5.3.8.min.css'), 'attr') ?>">
    <link rel="stylesheet" href="<?= esc(asset_url('css/app.css'), 'attr') ?>">
    <script src="<?= esc(asset_url('js/pwa.js'), 'attr') ?>" defer></script>
</head>
<body>
    <main class="auth-wrap">
        <div class="auth-card text-center">
            <svg class="text-muted mb-3" aria-hidden="true" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 8.8a15 15 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0"/><circle cx="12" cy="19.5" r="0.8"/><path d="M3 3l18 18"/></svg>
            <h1 class="h5"><?= esc(lang('Pwa.offlineTitle')) ?></h1>
            <p><?= esc(lang('Pwa.offlineBody')) ?></p>
            <button type="button" class="btn btn-primary btn-lg w-100" data-reload><?= esc(lang('Pwa.retry')) ?></button>
        </div>
    </main>
</body>
</html>
