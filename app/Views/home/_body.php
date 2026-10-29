<?php

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Work\BillingMonth;
use App\Libraries\Work\DashboardFilter;

/**
 * Telo prehladu (#dashboard-body): dlazdice po menach a skupiny uloh.
 *
 * @var DashboardFilter      $filter
 * @var array<string, mixed> $data
 * @var array<string, mixed> $overdue
 */
$locale   = service('request')->getLocale();
$due      = $data['due'];
$wip      = $data['wip'];
$groups   = $data['groups'];
$multiDue = count($due) > 1;
$multiWip = count($wip) > 1;
$query    = $filter->toQuery();
$clientQs = $filter->clientId !== null ? '&client=' . $filter->clientId : '';
$hours    = static fn (string $h): string => Hours::format($h, $locale);
// WR-02: odkazy vedu na zoznam s tou istou podmienkou ako dlazdica/skupina (TaskFilter::GROUPS).
$drill     = static fn (string $group): string => '/tasks?group=' . $group . '&month=' . $filter->month . $clientQs;
$groupDefs = [
    'done_due'    => ['Dashboard.groupDoneDue', $drill('done_due'), $filter->month],
    'partial'     => ['Dashboard.groupPartial', $drill('partial'), null],
    'in_progress' => ['Dashboard.groupInProgress', $drill('in_progress'), null],
];
$hasGroups = false;

foreach ($groups as $g) {
    $hasGroups = $hasGroups || $g['rows'] !== [];
}
?>
<div id="dashboard-body" hx-get="/fragments/dashboard?<?= htmlspecialchars(http_build_query($query), ENT_QUOTES) ?>" hx-trigger="allocationChanged from:body" hx-swap="outerHTML">
    <?= view('home/_overdue', ['overdue' => $overdue]) ?>

    <?php if ($due === [] && $wip === [] && ! $hasGroups) : ?>
        <section class="app-card">
            <p class="fw-semibold mb-1"><?= esc(lang('Dashboard.emptyHeading')) ?></p>
            <p class="text-muted mb-0"><?= esc(lang('Dashboard.emptyBody', [mb_strtolower(BillingMonth::label($filter->month, $locale))])) ?></p>
        </section>
    <?php endif ?>

    <?php if ($due !== [] || $wip !== []) : ?>
        <div class="work-tiles">
            <?php foreach ($due as $row) : ?>
                <a class="work-tile" href="<?= htmlspecialchars($drill('due'), ENT_QUOTES) ?>">
                    <span class="work-tile-label"><?= esc($multiDue ? lang('Dashboard.tileDueCurrency', [$row['currency']]) : lang('Dashboard.tileDue')) ?></span>
                    <span class="work-tile-amount"><?= esc(MoneyFormat::format($row['amount'], $row['currency'])) ?></span>
                    <span class="work-tile-hours"><?= esc(lang('Dashboard.tileDueHours', [$hours($row['hours'])])) ?></span>
                </a>
            <?php endforeach ?>
            <?php foreach ($wip as $row) : ?>
                <a class="work-tile" href="<?= htmlspecialchars('/tasks?state=in_progress' . $clientQs, ENT_QUOTES) ?>">
                    <span class="work-tile-label"><?= esc($multiWip ? lang('Dashboard.tileWipCurrency', [$row['currency']]) : lang('Dashboard.tileWip')) ?></span>
                    <span class="work-tile-amount"><?= esc(MoneyFormat::format($row['amount'], $row['currency'])) ?></span>
                    <span class="work-tile-hours"><?= esc(lang('Dashboard.tileWipHours', [$hours($row['hours'])])) ?></span>
                </a>
            <?php endforeach ?>
        </div>
    <?php endif ?>

    <?php foreach ($groupDefs as $key => [$titleKey, $allUrl, $badgeMonth]) : ?>
        <?php $group = $groups[$key]; ?>
        <?php if ($group['rows'] === []) : ?>
            <?php continue ?>
        <?php endif ?>
        <section class="work-group">
            <h2><?= esc(lang($titleKey)) ?> <span class="badge text-bg-secondary"><?= (int) $group['total'] ?></span></h2>
            <div class="app-card p-0 overflow-hidden">
                <?php foreach ($group['rows'] as $row) : ?>
                    <?= view('tasks/_task_row', [
                        'row'        => $row,
                        'showClient' => $filter->clientId === null,
                        'monthBadge' => $badgeMonth !== null ? BillingMonth::shortLabel($badgeMonth, $locale) : null,
                    ]) ?>
                <?php endforeach ?>
            </div>
            <?php if ((int) $group['total'] > count($group['rows'])) : ?>
                <a class="btn btn-link btn-touch d-inline-flex align-items-center" href="<?= htmlspecialchars($allUrl, ENT_QUOTES) ?>"><?= esc(lang('Dashboard.showAll')) ?></a>
            <?php endif ?>
        </section>
    <?php endforeach ?>
</div>
