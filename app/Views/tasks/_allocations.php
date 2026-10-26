<?php

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Work\BillingMonth;

/**
 * Zoznam fakturacnych casti: zamknute bez ovladacich prvkov, nezamknute s odkazom Upravit (D-12, ALC-05).
 *
 * @var array<string, mixed> $task
 */
$locale = service('request')->getLocale();
$taskId = (int) $task['id'];
?>
<section class="app-card mb-4" id="task-allocations" aria-labelledby="task-allocations-title">
    <h2 class="h5 mb-2" id="task-allocations-title"><?= esc(lang('Allocations.sectionTitle')) ?></h2>

    <?php if ($task['allocations'] === []) : ?>
        <p class="text-body-secondary mb-3"><?= esc(lang('Allocations.emptyParts')) ?></p>
    <?php else : ?>
        <div>
            <?php foreach ($task['allocations'] as $a) : ?>
                <?php $aid = (int) $a['id']; ?>
                <div class="work-alloc">
                    <div>
                        <div class="fw-semibold"><?= esc(BillingMonth::label(trim((string) $a['billing_month']), $locale)) ?></div>
                        <div class="small text-body-secondary"><?= esc(lang('Allocations.hoursValue', [Hours::format((string) $a['hours'], $locale)])) ?> · <?= esc(MoneyFormat::format((string) $a['amount'], (string) $a['currency'])) ?></div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($a['locked']) : ?>
                            <svg class="text-body-secondary" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 1a3 3 0 0 0-3 3v2H4a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1h-1V4a3 3 0 0 0-3-3m-2 3a2 2 0 1 1 4 0v2H6z"/></svg>
                            <span class="badge text-bg-secondary"><?= esc(lang('Allocations.lockedBadge')) ?></span>
                        <?php else : ?>
                            <span class="badge text-bg-light border"><?= esc(lang('Allocations.plannedBadge')) ?></span>
                            <a class="btn btn-outline-secondary btn-touch" href="/allocations/<?= $aid ?>/edit" hx-get="/allocations/<?= $aid ?>/edit" hx-target="#sheet" hx-swap="innerHTML"><?= esc(lang('Allocations.editLink')) ?></a>
                        <?php endif ?>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
        <?php if ($task['has_locked']) : ?>
            <p class="small text-body-secondary mt-2 mb-0"><?= esc(lang('Allocations.lockedHint')) ?></p>
        <?php endif ?>
    <?php endif ?>

    <a class="btn btn-outline-secondary btn-touch w-100 mt-3" href="/tasks/<?= $taskId ?>/allocations/new" hx-get="/tasks/<?= $taskId ?>/allocations/new" hx-target="#sheet" hx-swap="innerHTML"><?= esc(lang('Allocations.addButton')) ?></a>
</section>
