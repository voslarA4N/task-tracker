<?php

/**
 * Pociatocny obrat ako samostatna stranka (bez JS).
 *
 * @var array<int, array{id: int, name_internal: string, currency: string}> $entities
 * @var array<string, string>                                               $values
 * @var int                                                                 $selected
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Limits.openingTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <p class="mb-2"><a class="d-inline-block py-2" href="/settings/limits"><?= esc(lang('Limits.backToLimits')) ?></a></p>
    <section class="app-card" aria-labelledby="opening-form-title">
        <h1 class="h4 mb-1" id="opening-form-title"><?= esc(lang('Limits.openingTitle')) ?></h1>
        <p class="small text-body-secondary mb-3"><?= esc(($entities[$selected]['name_internal'] ?? '') . ' · ' . ($values['year'] ?? '')) ?></p>
        <?= view('settings/limits/_opening_form', get_defined_vars()) ?>
    </section>
</div>
<?= $this->endSection() ?>
