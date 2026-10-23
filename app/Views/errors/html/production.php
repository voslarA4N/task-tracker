<!doctype html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title><?= esc(lang('App.errorServerTitle')) ?></title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap-5.3.8.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <main class="app-main container text-center">
        <div class="app-card">
            <h1 class="h4"><?= esc(lang('App.errorServerTitle')) ?></h1>
            <p><?= esc(lang('App.errorServerText')) ?></p>
            <a class="btn btn-primary" href="/"><?= esc(lang('App.backHome')) ?></a>
        </div>
    </main>
</body>
</html>
