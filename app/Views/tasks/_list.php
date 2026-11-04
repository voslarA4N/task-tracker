<?php

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\MoneyFormat;
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
$locale    = service('request')->getLocale();
$dateFmt   = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'Europe/Bratislava');
$cols      = [
    'title'  => ['Tasks.colTitle', false],
    'client' => ['Tasks.colClient', false],
    'status' => ['Tasks.colStatus', false],
    'hours'  => ['Tasks.colHours', true],
    'amount' => ['Tasks.colAmount', true],
];

if ($filter->group !== null) {
    $groupLabel = lang($groupLabels[$filter->group]);
    $groupNote  = $filter->month !== null && in_array($filter->group, TaskFilter::MONTH_GROUPS, true)
        ? lang('Tasks.groupFilterMonth', [$groupLabel, mb_strtolower(BillingMonth::label($filter->month, service('request')->getLocale()))])
        : lang('Tasks.groupFilter', [$groupLabel]);
}
?>
<div id="task-list">
    <input type="hidden" name="sort" value="<?= esc($filter->sort, 'attr') ?>" form="task-filter">
    <input type="hidden" name="dir" value="<?= esc($filter->dir, 'attr') ?>" form="task-filter">
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
        <div class="dt-only-sm">
        <section class="app-card p-0 overflow-hidden" aria-label="<?= esc(lang('Tasks.listTitle')) ?>">
            <?php foreach ($rows as $row) : ?>
                <?= view('tasks/_task_row', ['row' => $row]) ?>
            <?php endforeach ?>
        </section>
        </div>
        <div class="dt-only-lg">
            <table class="table table-hover dt-table">
                <caption class="visually-hidden"><?= esc(lang('Tasks.tableCaption')) ?></caption>
                <thead>
                    <tr>
                        <?php foreach ($cols as $col => [$key, $num]) : ?>
                            <?php
                            $active = $filter->sort === $col;
                            $aria   = $active ? ($filter->dir === 'asc' ? 'ascending' : 'descending') : 'none';
                            $href   = '/tasks?' . http_build_query($filter->withSort($col));
                            ?>
                            <th scope="col" aria-sort="<?= esc($aria, 'attr') ?>"<?= $num ? ' class="dt-num"' : '' ?>>
                                <a class="dt-sort" href="<?= esc($href, 'attr') ?>" hx-get="<?= esc($href, 'attr') ?>" hx-target="#task-list" hx-swap="outerHTML" hx-push-url="true"><?= esc(lang($key)) ?><span aria-hidden="true"><?= $active ? ($filter->dir === 'asc' ? '▲' : '▼') : '' ?></span></a>
                            </th>
                        <?php endforeach ?>
                        <th scope="col"><?= esc(lang('Tasks.colAlloc')) ?></th>
                        <?php
                        $active = $filter->sort === 'done';
                        $aria   = $active ? ($filter->dir === 'asc' ? 'ascending' : 'descending') : 'none';
                        $href   = '/tasks?' . http_build_query($filter->withSort('done'));
                        ?>
                        <th scope="col" aria-sort="<?= esc($aria, 'attr') ?>">
                            <a class="dt-sort" href="<?= esc($href, 'attr') ?>" hx-get="<?= esc($href, 'attr') ?>" hx-target="#task-list" hx-swap="outerHTML" hx-push-url="true"><?= esc(lang('Tasks.colDone')) ?><span aria-hidden="true"><?= $active ? ($filter->dir === 'asc' ? '▲' : '▼') : '' ?></span></a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <?php
                        [$badgeClass, $badgeKey] = match (true) {
                            $row['billing_state'] === 'billed'  => ['text-bg-secondary', 'Tasks.stateBilled'],
                            $row['billing_state'] === 'partial' => ['text-bg-warning', 'Tasks.statePartial'],
                            $row['status'] === 'done'           => ['text-bg-success', 'Tasks.stateDone'],
                            default                             => ['text-bg-light border', 'Tasks.stateInProgress'],
                        };
                        $fmtH     = static fn (string $h): string => Hours::format($h, $locale);
                        $hasParts = (int) $row['locked_count'] + (int) $row['unlocked_count'] > 0;
                        $doneAt   = $row['done_at'] ?? null;
                        ?>
                        <tr>
                            <td><a href="/tasks/<?= (int) $row['id'] ?>"><?= esc((string) $row['title']) ?></a></td>
                            <td><?= esc((string) $row['client_name']) ?></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= esc(lang($badgeKey)) ?></span></td>
                            <td class="dt-num"><?= esc(lang('Tasks.hoursValue', [$fmtH((string) $row['hours'])])) ?></td>
                            <td class="dt-num"><?= esc(MoneyFormat::format((string) $row['quoted_amount'], (string) $row['currency'])) ?></td>
                            <td><?= $hasParts ? esc(lang('Tasks.rowAllocMeta', [$fmtH((string) $row['billed_hours']), $fmtH((string) $row['planned_hours']), $fmtH((string) $row['remaining'])])) : '—' ?></td>
                            <td><?= $doneAt !== null && $doneAt !== '' ? esc((string) $dateFmt->format(new DateTimeImmutable((string) $doneAt))) : '—' ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php if ($truncated) : ?>
            <p class="small text-body-secondary mt-2"><?= esc(lang('Tasks.truncatedHint')) ?></p>
        <?php endif ?>
    <?php endif ?>
</div>
