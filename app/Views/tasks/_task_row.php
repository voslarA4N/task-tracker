<?php

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\MoneyFormat;

/**
 * Zdielany riadok ulohy (zoznam aj dashboard).
 *
 * @var array<string, mixed> $row
 * @var bool                 $showClient
 * @var string|null          $monthBadge
 */
$showClient = $showClient ?? true;
$monthBadge = $monthBadge ?? null;
$locale     = service('request')->getLocale();
$currency   = (string) $row['currency'];
$hours      = static fn (string $h): string => Hours::format($h, $locale);

[$badgeClass, $badgeKey] = match (true) {
    $row['billing_state'] === 'billed' => ['text-bg-secondary', 'Tasks.stateBilled'],
    $row['billing_state'] === 'partial' => ['text-bg-warning', 'Tasks.statePartial'],
    $row['status'] === 'done'           => ['text-bg-success', 'Tasks.stateDone'],
    default                             => ['text-bg-light border', 'Tasks.stateInProgress'],
};

$parts = [];

if ($showClient) {
    $parts[] = (string) $row['client_name'];
}

$meta = [lang('Tasks.hoursValue', [$hours((string) $row['hours'])]), MoneyFormat::format((string) $row['quoted_amount'], $currency)];
$metaLine = $showClient
    ? lang('Tasks.rowMeta', [(string) $row['client_name'], $meta[0], $meta[1]])
    : $meta[0] . ' · ' . $meta[1];
$hasParts = (int) $row['locked_count'] + (int) $row['unlocked_count'] > 0;
?>
<a class="work-task" href="/tasks/<?= (int) $row['id'] ?>">
    <span class="work-task-title"><?= esc((string) $row['title']) ?></span>
    <span class="badge <?= $badgeClass ?>"><?= esc(lang($badgeKey)) ?></span>
    <?php if ($monthBadge !== null) : ?>
        <span class="badge text-bg-light border"><?= esc((string) $monthBadge) ?></span>
    <?php endif ?>
    <span class="work-task-meta w-100"><?= esc($metaLine) ?></span>
    <?php if ($hasParts) : ?>
        <?php
        $alloc = lang('Tasks.rowAllocMeta', [$hours((string) $row['billed_hours']), $hours((string) $row['planned_hours']), $hours((string) $row['remaining'])]);
        $label = lang('Tasks.progressLabel', [$hours((string) $row['billed_hours']), $hours((string) $row['planned_hours']), $hours((string) $row['remaining']), $hours((string) $row['hours'])]);
        ?>
        <span class="work-task-meta w-100"><?= esc($alloc) ?></span>
        <span class="work-progress w-100" role="img" aria-label="<?= esc($label, 'attr') ?>">
            <span class="work-billed work-seg-<?= (int) $row['billed_pct'] ?>"></span>
            <span class="work-planned work-seg-<?= (int) $row['planned_pct'] ?>"></span>
        </span>
    <?php endif ?>
</a>
