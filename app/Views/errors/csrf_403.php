<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('App.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="app-card">
    <p><?= esc(lang('App.csrfFailed')) ?></p>
    <a class="btn btn-primary" href="/"><?= esc(lang('App.backHome')) ?></a>
</div>
<?= $this->endSection() ?>
