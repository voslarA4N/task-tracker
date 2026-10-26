<?php

use App\Libraries\Work\BillingMonth;
use App\Libraries\Work\DashboardFilter;

/**
 * Panel zospodu: vyber mesiaca a klienta (GET formular, funguje bez JS).
 *
 * @var DashboardFilter            $filter
 * @var list<array<string, mixed>> $clients
 */
$locale  = service('request')->getLocale();
$current = BillingMonth::current();
$options = [
    BillingMonth::previous($current) => 'Dashboard.monthPrevious',
    $current                         => 'Dashboard.monthCurrent',
    BillingMonth::next($current)     => 'Dashboard.monthNext',
];
$isPreset = isset($options[$filter->month]);
?>
<div class="offcanvas offcanvas-bottom work-sheet" tabindex="-1" id="dashboard-switch" aria-labelledby="dashboard-switch-title">
    <div class="offcanvas-header px-0 pt-0">
        <h2 class="offcanvas-title h5" id="dashboard-switch-title"><?= esc(lang('Dashboard.switchTitle')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Allocations.close'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0">
        <form method="get" action="/">
            <fieldset class="mb-3">
                <legend class="form-label"><?= esc(lang('Dashboard.monthLegend')) ?></legend>
                <?php foreach ($options as $value => $key) : ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="month" id="dm-<?= esc($value, 'attr') ?>" value="<?= esc($value, 'attr') ?>"<?= $filter->month === $value ? ' checked' : '' ?>>
                        <label class="form-check-label" for="dm-<?= esc($value, 'attr') ?>"><?= esc(lang($key)) ?> (<?= esc(BillingMonth::label($value, $locale)) ?>)</label>
                    </div>
                <?php endforeach ?>
                <label class="form-label mt-2" for="dm-other"><?= esc(lang('Dashboard.monthOther')) ?></label>
                <input class="form-control" type="month" id="dm-other" name="month_other" value="<?= $isPreset ? '' : esc($filter->month, 'attr') ?>">
            </fieldset>
            <div class="mb-3">
                <label class="form-label" for="dm-client"><?= esc(lang('Dashboard.clientLabel')) ?></label>
                <select class="form-select" id="dm-client" name="client">
                    <option value="all"<?= $filter->clientId === null ? ' selected' : '' ?>><?= esc(lang('Dashboard.allClientsOption')) ?></option>
                    <?php foreach ($clients as $c) : ?>
                        <option value="<?= (int) $c['id'] ?>"<?= $filter->clientId === (int) $c['id'] ? ' selected' : '' ?>><?= esc((string) $c['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <button class="btn btn-primary btn-touch w-100" type="submit"><?= esc(lang('Dashboard.show')) ?></button>
        </form>
    </div>
</div>
