<?php

use App\Libraries\Platform\MoneyFormat;

/**
 * Sekcia Po splatnosti na prehlade. Pri prazdnom zozname sa nevykresli.
 *
 * @var array{rows: list<array<string, mixed>>, total: int} $overdue
 */
if ($overdue['rows'] === []) {
    return;
}

$fmt = new IntlDateFormatter(service('request')->getLocale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
?>
<section class="work-group" aria-labelledby="overdue-title">
    <h2 id="overdue-title"><?= esc(lang('Dashboard.overdueTitle')) ?> <span class="badge text-bg-danger"><?= (int) $overdue['total'] ?></span></h2>
    <div class="app-card p-0 overflow-hidden">
        <?php foreach ($overdue['rows'] as $row) : ?>
            <?php
            $id      = (int) $row['id'];
            $dueDate = service('clock')->parseDate((string) $row['due_date']);
            $dueText = $dueDate === null ? (string) $row['due_date'] : (string) $fmt->format($dueDate);
            ?>
            <div class="border-bottom">
                <a class="ov-row" href="/invoices/<?= $id ?>">
                    <span class="d-flex justify-content-between gap-2">
                        <span><span class="fw-semibold"><?= esc((string) $row['number']) ?></span> <?= esc((string) $row['client_name']) ?></span>
                        <span class="text-nowrap tabular-nums"><?= esc(MoneyFormat::format((string) $row['balance'], (string) $row['currency'])) ?></span>
                    </span>
                    <span class="d-block small text-body-secondary"><?= esc(lang('Dashboard.overdueMeta', [$dueText, (int) $row['days_overdue']])) ?>
                        <?php if ($row['status'] === 'partially_paid') : ?>
                            <span class="badge text-bg-warning"><?= esc(lang('Invoices.statusPartiallyPaid')) ?></span>
                        <?php endif ?>
                    </span>
                </a>
                <div class="ov-actions px-3 pb-3">
                    <a class="btn btn-outline-secondary btn-touch" href="/invoices/<?= $id ?>/payments?return_to=/" hx-get="/invoices/<?= $id ?>/payments?return_to=/" hx-target="#sheet" hx-swap="innerHTML"><?= esc(lang('Dashboard.actionPayment')) ?></a>
                </div>
            </div>
        <?php endforeach ?>
    </div>
    <?php if ((int) $overdue['total'] > count($overdue['rows'])) : ?>
        <a class="btn btn-link btn-touch d-inline-flex align-items-center" href="/invoices?status=overdue"><?= esc(lang('Dashboard.overdueShowAll')) ?></a>
    <?php endif ?>
</section>
