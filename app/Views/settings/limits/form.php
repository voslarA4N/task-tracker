<?php

/**
 * Pridat/Upravit limit ako samostatna stranka (bez JS).
 *
 * @var array{id: int}|null $limit
 */
$title = lang($limit === null ? 'Limits.formNewTitle' : 'Limits.formEditTitle');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc($title) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <p class="mb-2"><a class="d-inline-block py-2" href="/settings/limits"><?= esc(lang('Limits.backToLimits')) ?></a></p>
    <section class="app-card" aria-labelledby="limit-form-title">
        <h1 class="h4 mb-3" id="limit-form-title"><?= esc($title) ?></h1>
        <?= view('settings/limits/_form', get_defined_vars()) ?>
    </section>
</div>
<?= $this->endSection() ?>
