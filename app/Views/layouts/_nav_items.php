<?php

declare(strict_types=1);

$path = '/' . trim(service('request')->getUri()->getPath(), '/');
?>
<a href="/"<?= $path === '/' ? ' aria-current="page"' : '' ?>><?= esc(lang('App.navOverview')) ?></a>
<span aria-disabled="true"><?= esc(lang('App.navTasks')) ?></span>
<span aria-disabled="true"><?= esc(lang('App.navInvoices')) ?></span>
<span aria-disabled="true"><?= esc(lang('App.navMore')) ?></span>
