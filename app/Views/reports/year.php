<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Reports.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-table">
    <p class="mb-2"><a class="d-inline-block py-2" href="/more"><?= esc(lang('Reports.backToMore')) ?></a></p>
    <?= view('reports/_year_body', get_defined_vars()) ?>
</div>
<?= $this->endSection() ?>
