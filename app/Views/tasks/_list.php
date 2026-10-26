<?php

use App\Libraries\Work\BillingMonth;
use App\Libraries\Work\TaskFilter;

/**
 * Fragment zoznamu uloh (#task-list).
 *
 * @var TaskFilter                       $filter
 * @var list<array<string, mixed>>       $rows
 * @var bool                             $truncated
 */
$count       = count($rows);
$groupLabels = [
    'due'         => 'Tasks.groupDue',
    'done_due'    => 'Dashboard.groupDoneDue',
    'partial'     => 'Dashboard.groupPartial',
    'in_progress' => 'Dashboard.groupInProgress',
];
$groupNote = '';

if ($filter->group !== null) {
    $groupLabel = lang($groupLabels[$filter->group]);
    $groupNote  = $filter->month !== null && in_array($filter->group, TaskFilter::MONTH_GROUPS, true)
        ? lang('Tasks.groupFilterMonth', [$groupLabel, mb_strtolower(BillingMonth::label($filter->month, service('request')->getLocale()))])
        : lang('Tasks.groupFilter', [$groupLabel]);
}
?>
<div id="task-list">
    <?php if ($groupNote !== '') : ?>
        <p class="small mb-2"><?= esc($groupNote) ?> · <a href="/tasks"><?= esc(lang('Tasks.filterClear')) ?></a></p>
    <?php endif ?>
    <p class="small text-body-secondary mb-2"><?= esc(lang('Tasks.listMeta', [$count])) ?></p>
    <?php if ($rows === []) : ?>
        <section class="app-card">
            <?php if ($filter->activeCount() === 0) : ?>
                <p class="fw-semibold mb-1"><?= esc(lang('Tasks.emptyHeading')) ?></p>
                <p class="text-muted mb-0"><?= esc(lang('Tasks.emptyBody')) ?></p>
            <?php else : ?>
                <p class="fw-semibold mb-1"><?= esc(lang('Tasks.emptyFilterHeading')) ?></p>
                <p class="text-muted mb-0"><?= esc(lang('Tasks.emptyFilterBody')) ?></p>
            <?php endif ?>
        </section>
    <?php else : ?>
        <section class="app-card p-0 overflow-hidden" aria-label="<?= esc(lang('Tasks.listTitle')) ?>">
            <?php foreach ($rows as $row) : ?>
                <?= view('tasks/_task_row', ['row' => $row]) ?>
            <?php endforeach ?>
        </section>
        <?php if ($truncated) : ?>
            <p class="small text-body-secondary mt-2"><?= esc(lang('Tasks.truncatedHint')) ?></p>
        <?php endif ?>
    <?php endif ?>
</div>
