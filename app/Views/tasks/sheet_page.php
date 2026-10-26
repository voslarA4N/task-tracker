<?php

/**
 * Panel casti ako celá stranka (bez JS).
 *
 * @var array<string, mixed> $task
 * @var string               $mode
 * @var string               $inner
 */
$titleKey = $mode === 'add' ? 'Allocations.sheetAddTitle' : ($mode === 'edit' ? 'Allocations.sheetEditTitle' : 'Allocations.deleteTitle');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang($titleKey)) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-narrow">
    <p class="mb-2"><a href="/tasks/<?= (int) $task['id'] ?>"><?= esc((string) $task['title']) ?></a></p>
    <section class="app-card" aria-labelledby="sheet-page-title">
        <h1 class="h4 mb-3" id="sheet-page-title"><?= esc(lang($titleKey)) ?></h1>
        <div id="sheet-body">
            <?= $this->include($inner) ?>
        </div>
    </section>
</div>
<?= $this->endSection() ?>
