<?php

/**
 * Presun casti ako cela stranka (bez JS).
 *
 * @var string $taskTitle
 * @var string $backUrl
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Invoicing.moveTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-narrow">
    <p class="mb-2"><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>"><?= esc(lang('Invoicing.title')) ?></a></p>
    <section class="app-card" aria-labelledby="sheet-page-title">
        <h1 class="h4 mb-3" id="sheet-page-title"><?= esc(lang('Invoicing.moveTitle')) ?></h1>
        <div id="sheet-body">
            <?= view('invoicing/_move_form', get_defined_vars()) ?>
        </div>
    </section>
</div>
<?= $this->endSection() ?>
