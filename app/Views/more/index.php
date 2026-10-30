<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="list-group more-list">
    <a class="list-group-item list-group-item-action more-item" href="/settings/entities"><?= esc(lang('Entities.navTitle')) ?></a>
    <a class="list-group-item list-group-item-action more-item" href="/settings/clients"><?= esc(lang('Clients.navTitle')) ?></a>
    <a class="list-group-item list-group-item-action more-item" href="/settings/legal-texts"><?= esc(lang('LegalTexts.navTitle')) ?></a>
    <a class="list-group-item list-group-item-action more-item" href="/settings/fx"><?= esc(lang('FxSettings.navTitle')) ?></a>
    <a class="list-group-item list-group-item-action more-item" href="/settings/email-templates"><?= esc(lang('EmailTemplates.navTitle')) ?><span class="d-block small text-body-secondary"><?= esc(lang('EmailTemplates.navHint')) ?></span></a>
    <a class="list-group-item list-group-item-action more-item" href="/settings/security"><?= esc(lang('Account.securityTitle')) ?></a>
    <form method="post" action="/logout" class="m-0">
        <?= csrf_field() ?>
        <button type="submit" class="list-group-item list-group-item-action more-item text-start"><?= esc(lang('Login.logout')) ?></button>
    </form>
</div>
<?= $this->endSection() ?>
