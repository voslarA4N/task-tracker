<?php

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\MoneyFormat;

/**
 * Suhrn hodin ulohy: nacenene, vyfakturovane (zamknute), naplanovane (nezamknute), zostava (D-05, D-12, TSK-05).
 *
 * @var array<string, mixed> $task
 */
$locale   = service('request')->getLocale();
$currency = (string) $task['currency'];
$hours    = static fn (string $h): string => lang('Allocations.hoursValue', [Hours::format($h, $locale)]);
$line     = static fn (string $h, string $amount): string => $hours($h) . ' · ' . MoneyFormat::format($amount, $currency);

$billed  = (string) $task['billed_hours'];
$planned = (string) $task['planned_hours'];
$left    = (string) $task['remaining'];
$label   = lang('Allocations.progressLabel', [
    Hours::format($billed, $locale),
    Hours::format($planned, $locale),
    Hours::format($left, $locale),
    Hours::format((string) $task['hours'], $locale),
]);

$delta = null;

if ($task['status'] === 'done' || $task['over_quoted']) {
    $d     = (string) $task['delta'];
    $delta = match (true) {
        Hours::compare($d, '0') > 0 => lang('Allocations.deltaOver', [Hours::formatSigned($d, $locale)]),
        Hours::compare($d, '0') < 0 => lang('Allocations.deltaUnder', [Hours::formatSigned($d, $locale)]),
        default                     => lang('Allocations.deltaWithin'),
    };
}
?>
<section class="app-card mb-4" id="task-summary" aria-labelledby="task-summary-title">
    <h2 class="visually-hidden" id="task-summary-title"><?= esc(lang('Allocations.quoted')) ?></h2>
    <dl class="master-kv mb-3">
        <dt><?= esc(lang('Allocations.quoted')) ?></dt>
        <dd class="fs-4 fw-semibold tabular-nums"><?= esc($line((string) $task['hours'], (string) $task['quoted_amount'])) ?></dd>
        <dt><?= esc(lang('Allocations.billed')) ?></dt>
        <dd class="tabular-nums"><?= esc($line($billed, (string) $task['billed_amount'])) ?></dd>
        <dt><?= esc(lang('Allocations.planned')) ?></dt>
        <dd class="tabular-nums"><?= esc($line($planned, (string) $task['planned_amount'])) ?></dd>
        <dt><?= esc(lang('Allocations.remaining')) ?></dt>
        <dd class="tabular-nums"><?= esc($hours($left)) ?></dd>
    </dl>
    <div class="work-progress mb-2" role="img" aria-label="<?= esc($label, 'attr') ?>">
        <span class="work-billed work-seg-<?= (int) $task['billed_pct'] ?>"></span>
        <span class="work-planned work-seg-<?= (int) $task['planned_pct'] ?>"></span>
    </div>
    <p class="small text-body-secondary mb-0"><?= esc($label) ?></p>
    <?php if ($delta !== null) : ?>
        <p class="work-delta mt-2 mb-0">
            <?= esc($delta) ?>
            <?php if ($task['over_quoted']) : ?>
                <span class="badge text-bg-warning ms-1"><?= esc(lang('Allocations.overBadge')) ?></span>
            <?php endif ?>
        </p>
    <?php endif ?>
</section>
