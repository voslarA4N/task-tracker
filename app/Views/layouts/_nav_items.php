<?php

declare(strict_types=1);

$path = '/' . trim(service('request')->getUri()->getPath(), '/');
?>
<a href="/"<?= $path === '/' ? ' aria-current="page"' : '' ?>><?= esc(lang('App.navOverview')) ?></a>
<a href="/tasks"<?= $path === '/tasks' || str_starts_with($path, '/tasks/') ? ' aria-current="page"' : '' ?>><?= esc(lang('App.navTasks')) ?></a>
<a href="/invoices"<?= $path === '/invoices' || str_starts_with($path, '/invoices/') ? ' aria-current="page"' : '' ?>><?= esc(lang('App.navInvoices')) ?></a>
<a href="/more"<?= $path === '/more' || str_starts_with($path, '/settings') ? ' aria-current="page"' : '' ?>><?= esc(lang('App.navMore')) ?></a>
