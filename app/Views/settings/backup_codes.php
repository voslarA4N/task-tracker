<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Account.codesTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="app-card security-card" aria-labelledby="codes-title">
    <h2 class="h5" id="codes-title"><?= esc(lang('Account.codesTitle')) ?></h2>
    <div class="alert alert-warning" role="alert"><?= esc(lang('TwoFactor.codesWarning')) ?></div>

    <?php if (! empty($error)) : ?>
        <div class="alert alert-danger" role="alert" id="codes-error"><?= esc($error) ?></div>
    <?php endif ?>

    <?= $this->include('auth/_backup_codes_grid') ?>

    <form method="post" action="/settings/security/backup-codes/done" x-data="codesAck" novalidate>
        <?= csrf_field() ?>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="acknowledged" value="1" id="acknowledged" @change="toggle">
            <label class="form-check-label" for="acknowledged"><?= esc(lang('TwoFactor.codesAck')) ?></label>
        </div>
        <button type="submit" class="btn btn-primary btn-touch" disabled :disabled="!acked"><?= esc(lang('TwoFactor.continue')) ?></button>
    </form>
</section>
<?= $this->endSection() ?>
