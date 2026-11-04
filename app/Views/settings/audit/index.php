<?php

/**
 * @var list<array<string, mixed>>       $rows
 * @var bool                             $hasMore
 * @var App\Libraries\Audit\AuditFilter  $filter
 * @var array<string, string>            $entityOptions
 * @var array<string, string>            $actionOptions
 * @var list<array{id: int, label: string}> $users
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('AuditLog.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-table">
    <p class="mb-2"><a class="d-inline-block py-2" href="/more"><?= esc(lang('AuditLog.backToMore')) ?></a></p>
    <h1 class="h4 mb-1"><?= esc(lang('AuditLog.title')) ?></h1>
    <p class="small text-body-secondary mb-3"><?= esc(lang('AuditLog.intro')) ?></p>

    <form id="audit-filter" class="mb-3" method="get" action="/settings/audit" hx-get="/settings/audit" hx-trigger="change, submit" hx-target="#audit-list" hx-swap="outerHTML" hx-push-url="true">
        <div class="dt-filters">
            <div>
                <label class="form-label small mb-1" for="aud-entity"><?= esc(lang('AuditLog.filterEntity')) ?></label>
                <select class="form-select btn-touch" id="aud-entity" name="entity">
                    <option value=""><?= esc(lang('AuditLog.all')) ?></option>
                    <?php foreach ($entityOptions as $key => $label) : ?>
                        <option value="<?= esc((string) $key, 'attr') ?>"<?= $filter->entityType === (string) $key ? ' selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1" for="aud-action"><?= esc(lang('AuditLog.filterAction')) ?></label>
                <select class="form-select btn-touch" id="aud-action" name="action">
                    <option value=""><?= esc(lang('AuditLog.all')) ?></option>
                    <?php foreach ($actionOptions as $key => $label) : ?>
                        <option value="<?= esc((string) $key, 'attr') ?>"<?= $filter->action === (string) $key ? ' selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1" for="aud-user"><?= esc(lang('AuditLog.filterUser')) ?></label>
                <select class="form-select btn-touch" id="aud-user" name="user">
                    <option value=""><?= esc(lang('AuditLog.allUsers')) ?></option>
                    <?php foreach ($users as $u) : ?>
                        <option value="<?= (int) $u['id'] ?>"<?= $filter->userId === $u['id'] ? ' selected' : '' ?>><?= esc($u['label']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1" for="aud-from"><?= esc(lang('AuditLog.filterFrom')) ?></label>
                <input class="form-control btn-touch" type="date" id="aud-from" name="from" value="<?= esc((string) $filter->from, 'attr') ?>">
            </div>
            <div>
                <label class="form-label small mb-1" for="aud-to"><?= esc(lang('AuditLog.filterTo')) ?></label>
                <input class="form-control btn-touch" type="date" id="aud-to" name="to" value="<?= esc((string) $filter->to, 'attr') ?>">
            </div>
        </div>
        <noscript><button type="submit" class="btn btn-primary btn-touch mt-2"><?= esc(lang('AuditLog.apply')) ?></button></noscript>
        <?php if ($filter->isActive()) : ?>
            <a class="d-inline-block py-2 mt-1" href="/settings/audit"><?= esc(lang('AuditLog.clear')) ?></a>
        <?php endif ?>
    </form>

    <?= view('settings/audit/_list', get_defined_vars()) ?>
    <div id="sheet"></div>
</div>
<?= $this->endSection() ?>
