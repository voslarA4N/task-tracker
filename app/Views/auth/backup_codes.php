<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card">
    <h2 class="h5 mb-3"><?= esc(lang('TwoFactor.codesTitle')) ?></h2>
    <div class="alert alert-warning" role="alert"><?= esc(lang('TwoFactor.codesWarning')) ?></div>

    <?php if (! empty($error)) : ?>
        <div class="alert alert-danger" role="alert" id="codes-error"><?= esc($error) ?></div>
    <?php endif ?>

    <?= $this->include('auth/_backup_codes_grid') ?>

    <form method="post" action="/2fa/backup-codes" x-data="codesAck" novalidate>
        <?= csrf_field() ?>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="acknowledged" value="1" id="acknowledged" @change="toggle">
            <label class="form-check-label" for="acknowledged"><?= esc(lang('TwoFactor.codesAck')) ?></label>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100" disabled :disabled="!acked"><?= esc(lang('TwoFactor.continue')) ?></button>
    </form>
</div>
<?= $this->endSection() ?>
