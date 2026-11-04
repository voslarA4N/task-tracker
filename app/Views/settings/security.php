<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Account.securityTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
<?= $this->include('settings/_password') ?>
<?= $this->include('settings/_twofactor') ?>
<?= $this->include('settings/_devices') ?>
<?= $this->include('settings/_events') ?>
</div>
<?= $this->endSection() ?>
