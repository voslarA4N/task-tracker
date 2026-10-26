<?php

use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Work\BillingMonth;
use App\Libraries\Work\DashboardFilter;

/**
 * @var DashboardFilter                  $filter
 * @var array<string, mixed>|null        $client
 * @var list<array<string, mixed>>       $clients
 * @var array<string, mixed>             $data
 */
$locale = service('request')->getLocale();
$meta   = $client === null
    ? lang('Dashboard.allClients')
    : lang('Dashboard.rateMeta', [(string) $client['name'], MoneyFormat::format((string) $client['hourly_rate'], trim((string) $client['currency']))]);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('App.homeSubtitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0"><?= esc(BillingMonth::label($filter->month, $locale)) ?></h1>
            <p class="small text-body-secondary mb-0"><?= esc($meta) ?></p>
        </div>
        <button class="btn btn-outline-secondary btn-touch" type="button" data-bs-toggle="offcanvas" data-bs-target="#dashboard-switch" aria-controls="dashboard-switch"><?= esc(lang('Dashboard.change')) ?></button>
    </div>

    <?= $this->include('home/_switch_sheet') ?>
    <?= $this->include('home/_body') ?>
</div>

<a class="work-fab" href="/tasks/new" aria-label="<?= htmlspecialchars(lang('Tasks.new'), ENT_QUOTES) ?>">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
</a>
<?= $this->endSection() ?>
