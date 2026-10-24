<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="list-group more-list">
    <a class="list-group-item list-group-item-action more-item" href="/settings/security"><?= esc(lang('Account.securityTitle')) ?></a>
    <form method="post" action="/logout" class="m-0">
        <?= csrf_field() ?>
        <button type="submit" class="list-group-item list-group-item-action more-item text-start"><?= esc(lang('Login.logout')) ?></button>
    </form>
</div>
<?= $this->endSection() ?>
