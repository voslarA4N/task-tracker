<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Demo.subtitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<?= $this->include('demo/_calculator') ?>
<?= $this->endSection() ?>
