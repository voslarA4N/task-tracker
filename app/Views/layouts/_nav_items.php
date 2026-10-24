<?php

declare(strict_types=1);

$path = '/' . trim(service('request')->getUri()->getPath(), '/');
?>
<a href="/"<?= $path === '/' ? ' aria-current="page"' : '' ?>><?= esc(lang('App.navOverview')) ?></a>
<span aria-disabled="true"><?= esc(lang('App.navTasks')) ?></span>
<span aria-disabled="true"><?= esc(lang('App.navInvoices')) ?></span>
<a href="/more"<?= $path === '/more' || str_starts_with($path, '/settings') ? ' aria-current="page"' : '' ?>><?= esc(lang('App.navMore')) ?></a>
