<?php

/**
 * @var list<array<string, mixed>> $rows
 * @var bool                       $truncated
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

    <?= view('invoices/_list', ['rows' => $rows, 'truncated' => $truncated]) ?>
</div>
<?= $this->endSection() ?>
