<?php

declare(strict_types=1);

$path = '/' . trim(service('request')->getUri()->getPath(), '/');
?>
<a href="/"<?= $path === '/' ? ' aria-current="page"' : '' ?>><?= view('layouts/_icon', ['name' => 'overview', 'size' => 24, 'class' => 'nav-icon'], ['saveData' => false]) ?><?= esc(lang('App.navOverview')) ?></a>
<a href="/tasks"<?= $path === '/tasks' || str_starts_with($path, '/tasks/') ? ' aria-current="page"' : '' ?>><?= view('layouts/_icon', ['name' => 'tasks', 'size' => 24, 'class' => 'nav-icon'], ['saveData' => false]) ?><?= esc(lang('App.navTasks')) ?></a>
<a href="/invoices"<?= $path === '/invoices' || str_starts_with($path, '/invoices/') ? ' aria-current="page"' : '' ?>><?= view('layouts/_icon', ['name' => 'invoices', 'size' => 24, 'class' => 'nav-icon'], ['saveData' => false]) ?><?= esc(lang('App.navInvoices')) ?></a>
<a href="/more"<?= $path === '/more' || str_starts_with($path, '/settings') || str_starts_with($path, '/reports') ? ' aria-current="page"' : '' ?>><?= view('layouts/_icon', ['name' => 'more', 'size' => 24, 'class' => 'nav-icon'], ['saveData' => false]) ?><?= esc(lang('App.navMore')) ?></a>
