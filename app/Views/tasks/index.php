<?php

use App\Libraries\Work\TaskFilter;

/**
 * @var TaskFilter                       $filter
 * @var list<array<string, mixed>>       $rows
 * @var bool                             $truncated
 * @var list<array<string, mixed>>       $clients
 */
$states = [
    'current'     => 'Tasks.stateCurrent',
    'in_progress' => 'Tasks.stateInProgress',
    'done'        => 'Tasks.stateFilterDone',
    'partial'     => 'Tasks.stateFilterPartial',
    'billed'      => 'Tasks.stateFilterBilled',
    'all'         => 'Tasks.stateAll',
];
$active = $filter->activeCount();
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Tasks.listTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h4 mb-0"><?= esc(lang('Tasks.listTitle')) ?></h1>
        <a class="btn btn-primary btn-touch d-none d-md-inline-flex align-items-center" href="/tasks/new"><?= esc(lang('Tasks.new')) ?></a>
    </div>

    <button class="btn btn-outline-secondary btn-touch w-100 mb-2 d-sm-none" type="button" data-bs-toggle="collapse" data-bs-target="#task-filters" aria-expanded="false" aria-controls="task-filters"><?= esc(lang('Tasks.filtersCount', [$active])) ?></button>

    <div class="collapse d-sm-block" id="task-filters">
        <form class="app-card mb-3" method="get" action="/tasks"
              hx-get="/tasks" hx-target="#task-list" hx-swap="outerHTML" hx-push-url="true"
              hx-trigger="change, keyup changed delay:300ms from:#f-q" hx-indicator="#task-filter-indicator">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="f-client"><?= esc(lang('Tasks.filterClient')) ?></label>
                    <select class="form-select" id="f-client" name="client">
                        <option value=""><?= esc(lang('Tasks.filterClientAll')) ?></option>
                        <?php foreach ($clients as $client) : ?>
                            <option value="<?= (int) $client['id'] ?>"<?= $filter->clientId === (int) $client['id'] ? ' selected' : '' ?>><?= esc((string) $client['name']) ?><?= $client['is_archived'] ? ' (' . esc(lang('Tasks.archivedBadge')) . ')' : '' ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f-state"><?= esc(lang('Tasks.filterState')) ?></label>
                    <select class="form-select" id="f-state" name="state">
                        <?php foreach ($states as $value => $key) : ?>
                            <option value="<?= $value ?>"<?= $filter->state === $value ? ' selected' : '' ?>><?= esc(lang($key)) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f-month"><?= esc(lang('Tasks.filterMonth')) ?></label>
                    <input class="form-control" type="month" id="f-month" name="month" value="<?= esc((string) $filter->month, 'attr') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f-q"><?= esc(lang('Tasks.filterSearch')) ?></label>
                    <input class="form-control" type="search" id="f-q" name="q" value="<?= esc((string) $filter->q, 'attr') ?>" placeholder="<?= esc(lang('Tasks.filterSearchPlaceholder'), 'attr') ?>" autocomplete="off">
                </div>
            </div>
            <div class="d-flex align-items-center gap-3 mt-3">
                <button class="btn btn-outline-secondary btn-touch" type="submit"><?= esc(lang('Tasks.filterApply')) ?></button>
                <a href="/tasks"><?= esc(lang('Tasks.filterClear')) ?></a>
                <span class="htmx-indicator small text-body-secondary" id="task-filter-indicator" aria-hidden="true">…</span>
            </div>
        </form>
    </div>

    <?= view('tasks/_list', ['filter' => $filter, 'rows' => $rows, 'truncated' => $truncated]) ?>
</div>

<a class="work-fab" href="/tasks/new" aria-label="<?= htmlspecialchars(lang('Tasks.new'), ENT_QUOTES) ?>">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
</a>
<?= $this->endSection() ?>
