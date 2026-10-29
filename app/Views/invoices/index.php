<?php

/**
 * @var list<array<string, mixed>> $rows
 * @var bool                       $truncated
 * @var string                     $status
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Invoices.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h4 mb-0"><?= esc(lang('Invoices.title')) ?></h1>
        <a class="btn btn-primary btn-touch d-inline-flex align-items-center" href="/invoices/new"><?= esc(lang('Invoices.newCta')) ?></a>
    </div>

    <form class="d-flex align-items-end gap-2 mb-3" method="get" action="/invoices" hx-get="/invoices" hx-trigger="change, submit" hx-target="#invoice-list" hx-swap="outerHTML" hx-push-url="true">
        <div class="flex-grow-1">
            <label class="form-label small mb-1" for="inv-status"><?= esc(lang('Invoices.filterStatus')) ?></label>
            <select class="form-select btn-touch" id="inv-status" name="status">
                <?php foreach (['' => 'Invoices.filterAll', 'issued' => 'Invoices.statusIssued', 'sent' => 'Invoices.statusSent', 'partially_paid' => 'Invoices.statusPartiallyPaid', 'paid' => 'Invoices.statusPaid', 'overdue' => 'Invoices.filterOverdue'] as $value => $key) : ?>
                    <option value="<?= esc((string) $value, 'attr') ?>"<?= $status === (string) $value ? ' selected' : '' ?>><?= esc(lang($key)) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <noscript><button class="btn btn-outline-secondary btn-touch" type="submit"><?= esc(lang('Invoices.filterApply')) ?></button></noscript>
    </form>

    <?= view('invoices/_list', ['rows' => $rows, 'truncated' => $truncated, 'status' => $status]) ?>
</div>
<?= $this->endSection() ?>
