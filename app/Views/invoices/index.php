<?php

/**
 * @var list<array<string, mixed>> $rows
 * @var bool                       $truncated
 * @var string                     $status
 * @var InvoiceFilter              $filter
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $entities
 */

use App\Libraries\Invoicing\InvoiceFilter;

?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Invoices.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-table">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h4 mb-0"><?= esc(lang('Invoices.title')) ?></h1>
        <a class="btn btn-primary btn-touch d-inline-flex align-items-center" href="/invoices/new"><?= esc(lang('Invoices.newCta')) ?></a>
    </div>

    <form id="inv-filter" class="mb-3" method="get" action="/invoices" hx-get="/invoices" hx-trigger="change, submit" hx-target="#invoice-list" hx-swap="outerHTML" hx-push-url="true">
        <div class="dt-filters">
            <div>
                <label class="form-label small mb-1" for="inv-status"><?= esc(lang('Invoices.filterStatus')) ?></label>
                <select class="form-select btn-touch" id="inv-status" name="status">
                    <?php foreach (['' => 'Invoices.filterAll', 'issued' => 'Invoices.statusIssued', 'sent' => 'Invoices.statusSent', 'partially_paid' => 'Invoices.statusPartiallyPaid', 'paid' => 'Invoices.statusPaid', 'overdue' => 'Invoices.filterOverdue', 'cancelled' => 'Invoices.filterCancelled', 'credit_note' => 'Invoices.filterCreditNote'] as $value => $key) : ?>
                        <option value="<?= esc((string) $value, 'attr') ?>"<?= $status === (string) $value ? ' selected' : '' ?>><?= esc(lang($key)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="dt-only-lg">
                <label class="form-label small mb-1" for="inv-entity"><?= esc(lang('Invoices.filterEntity')) ?></label>
                <select class="form-select btn-touch" id="inv-entity" name="entity">
                    <option value=""><?= esc(lang('Invoices.filterEntityAll')) ?></option>
                    <?php foreach ($entities as $e) : ?>
                        <option value="<?= (int) $e['id'] ?>"<?= $filter->entityId === (int) $e['id'] ? ' selected' : '' ?>><?= esc((string) $e['name_internal']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="dt-only-lg">
                <label class="form-label small mb-1" for="inv-client"><?= esc(lang('Invoices.filterClient')) ?></label>
                <select class="form-select btn-touch" id="inv-client" name="client">
                    <option value=""><?= esc(lang('Invoices.filterClientAll')) ?></option>
                    <?php foreach ($clients as $c) : ?>
                        <option value="<?= (int) $c['id'] ?>"<?= $filter->clientId === (int) $c['id'] ? ' selected' : '' ?>><?= esc((string) $c['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="dt-only-lg">
                <label class="form-label small mb-1" for="inv-q"><?= esc(lang('Invoices.filterQ')) ?></label>
                <input class="form-control btn-touch" type="search" id="inv-q" name="q" maxlength="50" value="<?= esc((string) $filter->q, 'attr') ?>" placeholder="<?= esc(lang('Invoices.filterQPlaceholder'), 'attr') ?>">
            </div>
            <div class="dt-only-lg">
                <label class="form-label small mb-1" for="inv-from"><?= esc(lang('Invoices.filterFrom')) ?></label>
                <input class="form-control btn-touch" type="date" id="inv-from" name="from" value="<?= esc((string) $filter->from, 'attr') ?>">
            </div>
            <div class="dt-only-lg">
                <label class="form-label small mb-1" for="inv-to"><?= esc(lang('Invoices.filterTo')) ?></label>
                <input class="form-control btn-touch" type="date" id="inv-to" name="to" value="<?= esc((string) $filter->to, 'attr') ?>">
            </div>
        </div>
        <noscript><button class="btn btn-outline-secondary btn-touch mt-2" type="submit"><?= esc(lang('Invoices.filterApply')) ?></button></noscript>
    </form>

    <?= view('invoices/_list', ['rows' => $rows, 'truncated' => $truncated, 'status' => $status, 'filter' => $filter]) ?>
</div>
<?= $this->endSection() ?>
