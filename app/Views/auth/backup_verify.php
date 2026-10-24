<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card">
    <h2 class="h5 mb-3"><?= esc(lang('TwoFactor.backupLabel')) ?></h2>

    <?php if (! empty($error)) : ?>
        <div class="alert alert-danger" role="alert" id="backup-error"><?= esc($error) ?></div>
    <?php endif ?>

    <form method="post" action="/2fa/backup" data-submit-once novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="backup-code" class="form-label"><?= esc(lang('TwoFactor.backupLabel')) ?></label>
            <input type="text" id="backup-code" name="code"
                   class="form-control auth-code-input<?= ! empty($error) ? ' is-invalid' : '' ?>"
                   autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus required
                   placeholder="<?= esc(lang('TwoFactor.backupPlaceholder'), 'attr') ?>"
                   <?= ! empty($error) ? 'aria-describedby="backup-error"' : '' ?>>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('TwoFactor.verifySubmit')) ?>
        </button>
    </form>

    <p class="mt-3 text-center"><a href="/2fa/verify"><?= esc(lang('TwoFactor.useApp')) ?></a></p>

    <form method="post" action="/logout" class="mt-3 text-center">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-link"><?= esc(lang('TwoFactor.cancel')) ?></button>
    </form>
</div>
<?= $this->endSection() ?>
