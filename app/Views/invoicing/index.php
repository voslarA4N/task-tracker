<?php

/**
 * Fakturacia mesiaca: vyber a nahlad (#inv-preview).
 *
 * @var list<array<string, mixed>> $clients
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Invoicing.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <h1 class="h4 mb-1"><?= esc(lang('Invoicing.title')) ?></h1>
    <p class="small text-body-secondary mb-3"><?= esc(lang('Invoicing.subtitle')) ?></p>

    <?php if ($clients !== []) : ?>
        <?= view('invoicing/_picker', get_defined_vars()) ?>
    <?php endif ?>

    <?= view('invoicing/_preview', get_defined_vars()) ?>
</div>
<div id="sheet"></div>
<?= $this->endSection() ?>
