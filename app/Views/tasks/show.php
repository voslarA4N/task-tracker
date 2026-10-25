<?php

/**
 * @var array<string, mixed>  $task
 * @var array<string, string> $dates
 * @var string|null           $error
 */

use App\Libraries\Platform\MoneyFormat;

$id    = (int) $task['id'];
$state = (string) $task['billing_state'];
$badge = match (true) {
    $state === 'billed'        => ['text-bg-secondary', 'Tasks.stateBilled'],
    $state === 'partial'       => ['text-bg-warning', 'Tasks.statePartial'],
    $task['status'] === 'done' => ['text-bg-success', 'Tasks.stateDone'],
    default                    => ['text-bg-light border', 'Tasks.stateInProgress'],
};
$url          = (string) ($task['url'] ?? '');
$scheme       = strtolower((string) parse_url($url, PHP_URL_SCHEME));
$linkOk       = $url !== '' && in_array($scheme, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
$portal       = (string) ($task['portal_name'] ?? '');
$portal       = $portal === '' ? lang('Tasks.portalDefaultName') : $portal;
$empty        = lang('Tasks.empty');
$plannedCount = (int) $task['unlocked_count'];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc((string) $task['title']) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <?php if (($error ?? null) !== null) : ?>
        <div class="alert alert-danger" role="alert"><?= esc((string) $error) ?></div>
    <?php endif ?>

    <section class="app-card mb-4" id="task-header" aria-labelledby="task-title">
        <h1 class="h4 mb-1" id="task-title"><?= esc((string) $task['title']) ?></h1>
        <p class="small text-body-secondary mb-2"><?= esc(lang('Tasks.createdMeta', [(string) $task['client_name'], $dates['created_at']])) ?></p>
        <p class="mb-2">
            <span class="badge <?= esc($badge[0], 'attr') ?>"><?= esc(lang($badge[1])) ?></span>
            <?php if ($task['client_archived']) : ?>
                <span class="badge text-bg-secondary"><?= esc(lang('Tasks.archivedBadge')) ?></span>
            <?php endif ?>
        </p>
        <?php if ($linkOk) : ?>
            <p class="mb-3"><a class="d-inline-block py-2" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= esc(lang('Tasks.portalOpen', [$portal])) ?> &#8599;</a></p>
        <?php endif ?>

        <div class="master-actions">
            <a class="btn btn-outline-secondary btn-touch" href="/tasks/<?= $id ?>/edit"><?= esc(lang('Tasks.edit')) ?></a>
            <?php if (! $task['has_locked']) : ?>
                <button type="button" class="btn btn-outline-danger btn-touch" data-bs-toggle="modal" data-bs-target="#delete-task"><?= esc(lang('Tasks.delete')) ?></button>
            <?php endif ?>
        </div>
        <?php if ($task['has_locked']) : ?>
            <p class="small text-body-secondary mt-2 mb-0"><?= esc(lang('Tasks.deleteBlockedHint')) ?></p>
        <?php endif ?>
    </section>

    <!-- 04-10: summary + allocations -->

    <section class="app-card" id="task-data" aria-labelledby="task-data-title">
        <h2 class="h5 mb-3" id="task-data-title"><?= esc(lang('Tasks.sectionData')) ?></h2>
        <dl class="master-kv mb-0">
            <dt><?= esc(lang('Tasks.client')) ?></dt>
            <dd><?= esc((string) $task['client_name']) ?></dd>
            <dt><?= esc(lang('Tasks.rate')) ?></dt>
            <dd><?= esc(lang('Tasks.perHourUnit', [MoneyFormat::format((string) $task['rate'], (string) $task['currency'])])) ?></dd>
            <dt><?= esc(lang('Tasks.note')) ?></dt>
            <dd><?= ($task['note'] ?? null) === null || $task['note'] === '' ? esc($empty) : nl2br(esc((string) $task['note'])) ?></dd>
            <dt><?= esc(lang('Tasks.createdAt')) ?></dt>
            <dd><?= esc($dates['created_at']) ?></dd>
            <dt><?= esc(lang('Tasks.doneAt')) ?></dt>
            <dd><?= $dates['done_at'] === '' ? esc($empty) : esc($dates['done_at']) ?></dd>
            <dt><?= esc(lang('Tasks.url')) ?></dt>
            <dd><?= $url === '' ? esc($empty) : esc($url) ?></dd>
        </dl>
    </section>
</div>

<?php if (! $task['has_locked']) : ?>
    <div class="modal fade" id="delete-task" tabindex="-1" aria-labelledby="delete-task-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title h5" id="delete-task-title"><?= esc(lang('Tasks.deleteTitle')) ?></h3>
                </div>
                <div class="modal-body">
                    <p class="mb-0"><?= esc(lang('Tasks.deleteConfirm', [(string) $task['title'], (string) $plannedCount])) ?></p>
                </div>
                <div class="modal-footer flex-column flex-sm-row">
                    <form method="post" action="/tasks/<?= $id ?>/delete" class="w-100 w-sm-auto m-0" data-submit-once>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-danger w-100"><?= esc(lang('Tasks.delete')) ?></button>
                    </form>
                    <button type="button" class="btn btn-outline-secondary w-100 w-sm-auto" data-bs-dismiss="modal"><?= esc(lang('Tasks.cancel')) ?></button>
                </div>
            </div>
        </div>
    </div>
<?php endif ?>
<?= $this->endSection() ?>
