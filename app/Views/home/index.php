<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('App.homeSubtitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="app-card">
    <h2 class="h6"><?= esc(lang('App.homeHeading')) ?></h2>
    <p class="mb-0"><?= esc(lang('App.homeLead')) ?></p>
    <a class="btn btn-outline-primary mt-3" href="/demo"><?= esc(lang('Demo.homeLink')) ?></a>
</div>
<?= $this->endSection() ?>
