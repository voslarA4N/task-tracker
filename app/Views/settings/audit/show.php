<?php

/**
 * Detail záznamu ako celá stránka (bez JS).
 *
 * @var array<string, mixed> $row
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('AuditLog.detailTitle', [(int) $row['id']])) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-narrow">
    <p class="mb-2"><a class="d-inline-block py-2" href="/settings/audit"><?= esc(lang('AuditLog.title')) ?></a></p>
    <section class="app-card" aria-labelledby="audit-detail-title">
        <h1 class="h4 mb-2" id="audit-detail-title"><?= esc(lang('AuditLog.detailTitle', [(int) $row['id']])) ?></h1>
        <?= view('settings/audit/_detail', get_defined_vars()) ?>
    </section>
</div>
<?= $this->endSection() ?>
